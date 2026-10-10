{{-- Error inesperado: nunca muestra el detalle técnico (APP_DEBUG=false) --}}
@extends('errors::minimal')

@section('title', 'Error inesperado')
@section('code', '500')
@section('message', 'Ocurrió un error inesperado. Intenta de nuevo en unos minutos.')
