@extends('layouts.app')

@section('title', 'Available Tests')

@section('content')
<div class="bg-white rounded-lg shadow p-6">
    <h2 class="text-2xl font-bold mb-6">📚 Available Tests</h2>
    
    @forelse($tests as $test)
        <div class="border rounded-lg p-4 mb-4 hover:shadow-md transition">
            <div class="flex justify-between items-start">
                <div>
                    <h3 class="text-xl font-semibold">{{ $test->title }}</h3>
                    <p class="text-gray-600 mt-1">{{ $test->description ?? 'No description available' }}</p>
                    <div class="flex gap-4 mt-2 text-sm text-gray-500">
                        <span>⏱️ {{ $test->duration_minutes }} minutes</span>
                        <span>📝 {{ $test->questions_count }} questions</span>
                        @if($test->available_from)
                            <span>📅 Available from: {{ $test->available_from->format('M d, Y H:i') }}</span>
                        @endif
                    </div>
                </div>
                <a href="{{ route('test.start', $test->id) }}" 
                   class="bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600 transition">
                    Start Test
                </a>
            </div>
        </div>
    @empty
        <div class="text-center py-12">
            <p class="text-gray-500 text-lg">No tests available at the moment.</p>
            <p class="text-gray-400 text-sm mt-2">Please check back later.</p>
        </div>
    @endforelse
</div>
@endsection