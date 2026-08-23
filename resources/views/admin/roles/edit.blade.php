@extends('layouts.app')

@section('title', 'Edit Role')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Edit role</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.roles.update', $role) }}">
                        @csrf
                        @method('PUT')
                        @include('admin.roles._form', ['role' => $role])

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">Save changes</button>
                            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
