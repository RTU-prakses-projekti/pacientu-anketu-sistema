@extends('layouts.app')

@section('title', 'Student Submissions')

@section('content')
<div class="bg-white rounded-lg shadow p-6">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold">📊 Student Submissions</h2>
        <div class="flex gap-4">
            <form method="GET" class="flex gap-2">
                <select name="test_id" class="px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Tests</option>
                    @foreach($tests as $test)
                        <option value="{{ $test->id }}" {{ request('test_id') == $test->id ? 'selected' : '' }}>
                            {{ $test->title }}
                        </option>
                    @endforeach
                </select>
                <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 transition">
                    Filter
                </button>
            </form>
            <a href="{{ route('admin.submissions.export', ['test_id' => request('test_id')]) }}" 
               class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600 transition">
                📥 Export CSV
            </a>
        </div>
    </div>

    @if($submissions->count() > 0)
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Test</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Submitted</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($submissions as $submission)
                        <tr>
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900">{{ $submission->student_name }}</div>
                                <div class="text-sm text-gray-500">{{ $submission->student_id }}</div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">{{ $submission->test->title }}</td>
                            <td class="px-6 py-4">
                                <span class="font-medium">
                                    {{ $submission->score ?? 'N/A' }} / {{ $submission->total_possible ?? 'N/A' }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    {{ $submission->is_auto_submitted ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800' }}">
                                    {{ $submission->is_auto_submitted ? 'Auto-submitted' : 'Submitted' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ $submission->submitted_at ? $submission->submitted_at->diffForHumans() : 'N/A' }}
                            </td>
                            <td class="px-6 py-4 text-sm font-medium">
                                <a href="{{ route('admin.submissions.show', $submission) }}" 
                                   class="text-blue-600 hover:text-blue-900">View Details</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            {{ $submissions->links() }}
        </div>
    @else
        <div class="text-center py-12">
            <p class="text-gray-500">No submissions found.</p>
        </div>
    @endif
</div>
@endsection