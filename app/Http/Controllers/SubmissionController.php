<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use App\Models\Test;
use Illuminate\Http\Request;

class SubmissionController extends Controller
{
    public function index(Request $request)
    {
        $query = Submission::with('test');

        if ($request->has('test_id') && $request->test_id) {
            $query->where('test_id', $request->test_id);
        }

        $submissions = $query->orderBy('created_at', 'desc')->paginate(20);
        $tests = Test::all();

        return view('admin.submissions.index', compact('submissions', 'tests'));
    }

    public function show(Submission $submission)
    {
        $submission->load('answers.question.options', 'test');
        return view('admin.submissions.show', compact('submission'));
    }

    public function export(Request $request)
    {
        $query = Submission::with(['test', 'answers.question']);

        if ($request->has('test_id') && $request->test_id) {
            $query->where('test_id', $request->test_id);
        }

        $submissions = $query->get();

        // We'll create a simple CSV export
        $filename = 'test_results_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($submissions) {
            $file = fopen('php://output', 'w');
            
            // Headers
            fputcsv($file, [
                'Student ID', 
                'Student Name', 
                'Test Title', 
                'Score', 
                'Total Possible', 
                'Percentage', 
                'Started At', 
                'Submitted At', 
                'Auto Submitted'
            ]);

            // Data
            foreach ($submissions as $submission) {
                $percentage = $submission->total_possible > 0 
                    ? round(($submission->score / $submission->total_possible) * 100, 2) 
                    : 0;

                fputcsv($file, [
                    $submission->student_id,
                    $submission->student_name,
                    $submission->test->title,
                    $submission->score,
                    $submission->total_possible,
                    $percentage . '%',
                    $submission->started_at,
                    $submission->submitted_at,
                    $submission->is_auto_submitted ? 'Yes' : 'No'
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}