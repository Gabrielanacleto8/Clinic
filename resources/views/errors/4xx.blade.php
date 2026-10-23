@extends('errors.layout')

@section('code', (string) $exception->getStatusCode())
@section('title', 'Não foi possível concluir')
@section('message', 'O pedido não pôde ser atendido. Volte ao início e tente de novo.')
