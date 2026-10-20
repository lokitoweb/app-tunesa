@extends('errors.layout')

@section('title', ($title ?? 'Error') . ' — ' . config('app.name', 'ERP'))
@section('code', $code ?? '500')
@section('headline', $title ?? 'Error')
@section('message', $message ?? 'Ocurrió un problema inesperado.')
