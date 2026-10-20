<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use App\Services\RolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, list<Permission>>  $overrides
     * @return array<string, array<string, list<string>>>
     */
    private function payload(array $overrides = []): array
    {
        $permissions = [];

        foreach (UserRole::configurable() as $role) {
            $chosen = $overrides[$role->value] ?? Permission::defaultsFor($role);
            $permissions[$role->value] = array_map(fn (Permission $permission) => $permission->value, $chosen);
        }

        return ['permissions' => $permissions];
    }

    public function test_default_permissions_match_each_role(): void
    {
        $permissions = app(RolePermissions::class);

        $this->assertEqualsCanonicalizing(Permission::defaultsFor(UserRole::Professional), $permissions->for(UserRole::Professional));
        $this->assertEqualsCanonicalizing(Permission::defaultsFor(UserRole::Receptionist), $permissions->for(UserRole::Receptionist));
        $this->assertEqualsCanonicalizing(Permission::cases(), $permissions->for(UserRole::Admin));

        // Por padrão a recepção não chega perto do prontuário nem do financeiro.
        $reception = User::factory()->create();
        $this->assertFalse($reception->hasPermission(Permission::RecordsView));
        $this->assertFalse($reception->hasPermission(Permission::FinanceView));
        $this->assertTrue($reception->hasPermission(Permission::AgendaManage));
    }

    public function test_only_admins_can_see_or_change_permissions(): void
    {
        $this->get('/usuarios/permissoes')->assertRedirect('/login');

        foreach ([User::factory()->create(), User::factory()->professional()->create()] as $user) {
            $this->actingAs($user);
            $this->get('/usuarios/permissoes')->assertForbidden();
            $this->put('/usuarios/permissoes', $this->payload([UserRole::Receptionist->value => Permission::cases()]))->assertForbidden();
            $this->get('/')->assertDontSee(route('users.permissions.edit'), false);
        }

        $this->assertFalse(User::factory()->create()->hasPermission(Permission::FinanceView));

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/usuarios/permissoes')->assertOk()->assertSee('Ver a agenda')->assertSee('aria-label="Seções de usuários"', false);
        // A tela é uma aba de usuários, não um item do menu.
        $this->get('/usuarios')->assertSee(route('users.permissions.edit'), false);
        $this->get('/')->assertDontSee(route('users.permissions.edit'), false);
    }

    public function test_admin_can_grant_and_revoke_access_and_it_takes_effect_immediately(): void
    {
        $admin = User::factory()->admin()->create();
        $reception = User::factory()->create();
        $patient = Patient::factory()->create();

        $this->actingAs($reception)->get("/pacientes/{$patient->id}/ficha-de-saude")->assertForbidden();

        // Concede o prontuário à recepção.
        $this->actingAs($admin)->put('/usuarios/permissoes', $this->payload([
            UserRole::Receptionist->value => [...Permission::defaultsFor(UserRole::Receptionist), Permission::RecordsView, Permission::RecordsManage],
        ]))->assertRedirect(route('users.permissions.edit'))->assertSessionHasNoErrors();

        $this->actingAs($reception)->get("/pacientes/{$patient->id}/ficha-de-saude")->assertOk();

        // Retira a agenda da recepção.
        $this->actingAs($admin)->put('/usuarios/permissoes', $this->payload([
            UserRole::Receptionist->value => [Permission::PatientsView],
        ]))->assertSessionHasNoErrors();

        $this->actingAs($reception);
        $this->get('/agendamentos/novo')->assertForbidden();
        $this->get('/pacientes/novo')->assertForbidden();
        $this->get('/pacientes')->assertOk();
        $this->get("/pacientes/{$patient->id}/ficha-de-saude")->assertForbidden();
    }

    public function test_dependent_permissions_are_granted_together(): void
    {
        $this->actingAs(User::factory()->admin()->create())->put('/usuarios/permissoes', $this->payload([
            UserRole::Receptionist->value => [Permission::RecordsManage, Permission::PatientsArchive],
        ]))->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [Permission::RecordsManage, Permission::RecordsView, Permission::PatientsArchive, Permission::PatientsManage, Permission::PatientsView],
            app(RolePermissions::class)->for(UserRole::Receptionist),
        );
    }

    public function test_a_role_can_be_left_with_no_permissions(): void
    {
        // Sem nenhuma caixa marcada o navegador não envia o perfil.
        $this->actingAs(User::factory()->admin()->create())
            ->put('/usuarios/permissoes', ['permissions' => [UserRole::Professional->value => [Permission::AgendaView->value]]])
            ->assertSessionHasNoErrors();

        $this->assertSame([], app(RolePermissions::class)->for(UserRole::Receptionist));
        $this->assertSame([Permission::AgendaView], app(RolePermissions::class)->for(UserRole::Professional));
    }

    public function test_admin_access_cannot_be_reduced_or_stored(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put('/usuarios/permissoes', ['permissions' => ['admin' => [Permission::AgendaView->value]]])
            ->assertSessionHasErrors('permissions');

        $this->assertTrue($admin->hasPermission(Permission::FinanceManage));
        $this->assertSame(0, DB::table('role_permissions')->where('role', 'admin')->count());
    }

    public function test_unknown_permissions_and_roles_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->put('/usuarios/permissoes', ['permissions' => ['receptionist' => ['users.manage']]])->assertSessionHasErrors('permissions.receptionist.0');
        $this->put('/usuarios/permissoes', ['permissions' => ['receptionist' => ['manage-permissions']]])->assertSessionHasErrors('permissions.receptionist.0');
        $this->put('/usuarios/permissoes', ['permissions' => ['superuser' => [Permission::AgendaView->value]]])->assertSessionHasErrors('permissions');
        $this->put('/usuarios/permissoes', ['permissions' => 'tudo'])->assertSessionHasErrors('permissions');

        $this->assertEqualsCanonicalizing(Permission::defaultsFor(UserRole::Receptionist), app(RolePermissions::class)->for(UserRole::Receptionist));
    }

    public function test_changes_are_audited_and_unchanged_saves_are_not(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);
        $this->put('/usuarios/permissoes', $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(0, AuditLog::where('action', AuditAction::PermissionsUpdated->value)->count());

        $this->put('/usuarios/permissoes', $this->payload([
            UserRole::Receptionist->value => [Permission::PatientsView, Permission::AgendaView, Permission::FinanceView],
        ]));

        $log = AuditLog::where('action', AuditAction::PermissionsUpdated->value)->sole();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('receptionist', $log->metadata['role']);
        $this->assertSame(['finance.view'], $log->metadata['added']);
        $this->assertEqualsCanonicalizing(['patients.manage', 'agenda.manage'], $log->metadata['removed']);
    }

    public function test_permissions_are_read_from_the_database_once_per_request(): void
    {
        $user = User::factory()->professional()->create();
        Patient::factory()->count(3)->create();

        $this->actingAs($user);
        app(RolePermissions::class)->flush();
        DB::enableQueryLog();

        $this->get('/pacientes')->assertOk();

        $reads = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'role_permissions'));

        $this->assertCount(1, $reads);
    }

    public function test_sidebar_shows_only_the_modules_the_role_can_access(): void
    {
        $admin = User::factory()->admin()->create();
        $reception = User::factory()->create();

        $this->actingAs($admin)->put('/usuarios/permissoes', $this->payload([UserRole::Receptionist->value => [Permission::AgendaView]]));

        $html = $this->actingAs($reception)->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('href="'.route('patients.index').'"', $html);
        $this->assertStringNotContainsString('Novo agendamento', $html);
    }
}
