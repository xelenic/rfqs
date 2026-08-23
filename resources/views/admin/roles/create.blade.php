@extends('layouts.app')

@section('title', 'Add Role')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">New role</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.roles.store') }}">
                        @csrf
                        @include('admin.roles._form', ['role' => null])

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">Create role</button>
                            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
