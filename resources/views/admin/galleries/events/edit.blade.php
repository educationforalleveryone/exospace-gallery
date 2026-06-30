@extends('admin.galleries.events._form')

@section('form-title', "Edit: {$event->title}")
@section('form-action', route('admin.galleries.events.update', [$gallery, $event]))
@section('form-method', '@method(\'PUT\')')
@section('submit-label', 'Save changes')
