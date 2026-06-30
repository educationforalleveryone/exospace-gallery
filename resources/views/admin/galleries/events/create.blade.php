@extends('admin.galleries.events._form')

@section('form-title', 'New event')
@section('form-action', route('admin.galleries.events.store', $gallery))
@section('submit-label', 'Create event')
