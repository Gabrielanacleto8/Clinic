@extends('errors.layout')

@section('code', (string) $exception->getStatusCode())
@section('title', 'Erro no sistema')
@section('message', 'Algo deu errado do nosso lado. Tente de novo em instantes; se continuar, avise o administrador.')
