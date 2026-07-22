@extends('layouts.app')

@section('title', 'Manage Tests')

@section('content')
<div class="bg-white rounded-lg shadow p-6">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold">📋 Manage Tests</h2>
        <a href="{{ route('admin.tests.create') }}" 
           class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 transition">
            + Create New Test
        </a>
    </div>

    @if($tests->count() > 0)
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Questions</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($tests as $test)
                        <tr>
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900">{{ $test->title }}</div>
                                <div class="text-sm text-gray-500">{{ Str::limit($test->description, 50) }}</div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $test->questions_count }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $test->duration_minutes }} min</td>
                            <td class="px-6 py-4">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    {{ $test->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $test->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium">
                                <a href="{{ route('admin.tests.edit', $test) }}" class="text-blue-600 hover:text-blue-900 mr-3">Edit</a>
                                <form action="{{ route('admin.tests.toggle-status', $test) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="text-yellow-600 hover:text-yellow-900 mr-3">
                                        {{ $test->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                                <form action="{{ route('admin.tests.destroy', $test) }}" method="POST" class="inline" 
                                      onsubmit="return confirm('Delete this test and all its data?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="text-center py-12">
            <p class="text-gray-500">No tests created yet.</p>
            <a href="{{ route('admin.tests.create') }}" class="text-blue-500 hover:text-blue-700">
                Create your first test →
            </a>
        </div>
    @endif
</div>
@endsection