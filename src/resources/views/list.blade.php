@extends('web::layouts.app')

@section('title', trans('wanderer-sync::settings.title'))
@section('page_header', trans('wanderer-sync::settings.title'))

@section('content')
    <div class="card">
        <div class="card-body">
            <h5>{{ trans('wanderer-sync::settings.create_role_mapping') }}</h5>
            <form action="{{ route('wanderer-sync::createMapping') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="role-sel">{{ trans('wanderer-sync::settings.select_seat_role') }}</label>
                    <select class="form-control" id="role-sel" name="role">
                        @foreach($seat_roles as $role)
                            <option value="{{ $role->id }}">{{ $role->title }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">{{ trans('wanderer-sync::settings.help_mapping_role') }}</small>
                </div>
                <div class="form-group">
                    <label for="acl-sel">{{ trans('wanderer-sync::settings.select_access_list') }}</label>
                    <select class="form-control" id="acl-sel" name="acl">
                        @foreach($wanderer_access_lists as $list)
                            <option value="{{ $list->id }}">{{ $list->aclName() ?? $list->access_list_id }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">{{ trans('wanderer-sync::settings.help_mapping_list') }}</small>
                </div>
                <button type="submit" class="btn btn-primary">{{ trans('wanderer-sync::settings.add') }}</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5>{{ trans('wanderer-sync::settings.role_mapping') }}</h5>
            <table class="table">
                <thead>
                <tr>
                    <th>{{ trans('wanderer-sync::settings.role') }}</th>
                    <th>{{ trans_choice('wanderer-sync::settings.wanderer_access_list', 1) }}</th>
                    <th>{{ trans('wanderer-sync::settings.actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($roles as $role)
                    <tr>
                        <td>{{ $role->role->title }}</td>
                        <td>{{ $role->accessList->aclName() ?? $role->accessList->access_list_id }}</td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('wanderer-sync::deleteMapping') }}">
                                @csrf
                                <input type="hidden" name="id" value="{{ $role->id }}">
                                <button class="btn btn-danger btn-sm confirmdelete" type="submit">{{ trans('wanderer-sync::settings.delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5>{{ trans_choice('wanderer-sync::settings.wanderer_access_list', 2) }}</h5>
            <p class="text-muted small">{{ trans('wanderer-sync::settings.token_write_only_notice') }}</p>
            <form action="{{ route('wanderer-sync::createWandererAccessList') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="wanderer-url">{{ trans('wanderer-sync::settings.wanderer_url') }}</label>
                    <input type="text" class="form-control" id="wanderer-url" name="url" placeholder="{{ trans('wanderer-sync::settings.wanderer_url_placeholder') }}">
                </div>
                <div class="form-group">
                    <label for="wanderer-acl-id">{{ trans('wanderer-sync::settings.access_list_id') }}</label>
                    <input type="text" class="form-control" id="wanderer-acl-id" name="id" placeholder="{{ trans('wanderer-sync::settings.access_list_id_placeholder') }}">
                </div>
                <div class="form-group">
                    <label for="wanderer-token">{{ trans('wanderer-sync::settings.access_list_token') }}</label>
                    <input type="password" class="form-control" id="wanderer-token" name="token" placeholder="{{ trans('wanderer-sync::settings.access_list_token_placeholder') }}">
                </div>
                <button type="submit" class="btn btn-primary">{{ trans('wanderer-sync::settings.add') }}</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5>{{ trans_choice('wanderer-sync::settings.wanderer_access_list', 2) }}</h5>
            <table class="table">
                <thead>
                <tr>
                    <th>{{ trans('wanderer-sync::settings.wanderer_url') }}</th>
                    <th>{{ trans('wanderer-sync::settings.acl_name') }}</th>
                    <th>{{ trans('wanderer-sync::settings.access_list_id') }}</th>
                    <th>{{ trans('wanderer-sync::settings.actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($wanderer_access_lists as $access_list)
                    <tr>
                        <td>{{ $access_list->wanderer_url }}</td>
                        <td>{{ $access_list->aclName() ?? '-' }}</td>
                        <td><code>{{ $access_list->access_list_id }}</code></td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('wanderer-sync::deleteInstance') }}">
                                @csrf
                                <input type="hidden" name="id" value="{{ $access_list->id }}">
                                <button class="btn btn-danger btn-sm confirmdelete" type="submit">{{ trans('wanderer-sync::settings.delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@stop
