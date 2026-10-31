@extends(request()->ajax() ? 'layoutmodule::modal' : 'layoutmodule::account.main')

@section('title')
    Company Details
@endsection

@section('modal-size', 'lg')

@section('actions')
    <a href="{{ route('account.companies.edit', $company->id) }}" data-modal class="btn btn-secondary"><i data-lucide="pencil"></i> Edit</a>
    @include('companymodule::Account.partials.delete-form')
@endsection

@section('content')
    <dl class="detail-list">
        <div><dt>Name (Arabic)</dt><dd dir="rtl">{{ $company->name_ar }}</dd></div>
        <div><dt>Name (English)</dt><dd>{{ $company->name_en ?: '-' }}</dd></div>
        <div><dt>Added At</dt><dd>{{ $company->created_at->format('Y-m-d H:i') }}</dd></div>
        <div><dt>Last Update</dt><dd>{{ $company->updated_at->format('Y-m-d H:i') }}</dd></div>
        <div><dt>Commercial Register</dt><dd>{{ $company->commercial_register ?: '-' }}</dd></div>
        <div><dt>Tax Registration Number</dt><dd>{{ $company->tax_number ?: '-' }}</dd></div>
        <div><dt>Legal Representative</dt><dd dir="auto">{{ $company->representative_name ?: '-' }}</dd></div>
        <div><dt>Representative's Title</dt><dd dir="auto">{{ $company->representative_title ?: '-' }}</dd></div>
        <div><dt>Representative's National ID</dt><dd>{{ $company->representative_national_id ?: '-' }}</dd></div>
        <div class="wide"><dt>Address</dt><dd dir="auto">{{ $company->address ?: '-' }}</dd></div>
    </dl>

    @unless (request()->ajax())
        <div class="form-actions">
            <a href="{{ route('account.companies.index') }}" class="btn btn-secondary"><i data-lucide="arrow-left"></i> Back to Companies</a>
        </div>
    @endunless
@endsection
