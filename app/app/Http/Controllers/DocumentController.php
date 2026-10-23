<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use App\Jobs\ProcessUploadedPdf;
use App\Models\PdfUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class DocumentController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $documents = DB::table('documents')
            ->select(
                'relative_path',
                'filename',
                DB::raw('COUNT(*) AS total_pages'),
                DB::raw(
                    'COUNT(embedding) AS embedded_pages'
                ),
                DB::raw('MAX(updated_at) AS last_updated')
            )
            ->when($search !== '', function ($query) use ($search) {
                $query->where(
                    'filename',
                    'ILIKE',
                    '%' . addcslashes($search, '%_\\') . '%'
                );
            })
            ->groupBy('relative_path', 'filename')
            ->orderBy('filename')
            ->paginate(20)
            ->withQueryString();

            $uploads = PdfUpload::query()
                ->latest()
                ->paginate(10, ['*'], 'uploads_page');

        return view('documents.index', [
            'documents' => $documents,
            'search' => $search,
            'uploads' => $uploads,
        ]);
    }

    
    public function upload(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pdf' => [
                'required',
                'file',
                'mimes:pdf',
                'max:10240',
            ],
        ]);

        $file = $validated['pdf'];

        // Generate a unique filename to prevent overwriting PDFs.
        $filename = Str::uuid() . '.pdf';

        // Store inside the existing /documents Docker mount.
        $destination = '/documents/' . $filename;

        if (! $file->move('/documents', $filename)) {
            return back()->withErrors([
                'pdf' => 'Unable to save the PDF file.',
            ]);
        }

        $upload = PdfUpload::create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $filename,
            'status' => 'queued',
        ]);

        ProcessUploadedPdf::dispatch($upload->id);

        return redirect()
            ->route('documents.index')
            ->with(
                'success',
                'PDF uploaded successfully. Processing has been queued.'
            );
    }

    
    public function statuses(): JsonResponse
    {
        $uploads = PdfUpload::query()
            ->latest()
            ->limit(100)
            ->get([
                'id',
                'status',
                'total_pages',
                'embedded_pages',
                'error_message',
            ]);

        return response()->json([
            'uploads' => $uploads,
        ]);
    }

    
    public function destroy(PdfUpload $upload): RedirectResponse
    {
        if (in_array($upload->status, ['queued', 'processing'], true)) {
            return back()->withErrors([
                'document' => 'Cannot delete a PDF while processing.',
            ]);
        }

        $filename = $upload->stored_filename;

        if (! preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.pdf$/i',
            $filename
        )) {
            return back()->withErrors([
                'document' => 'Invalid PDF filename.',
            ]);
        }

        $path = '/documents/' . $filename;

        // Delete the physical file first.
        if (is_file($path) && ! unlink($path)) {
            return back()->withErrors([
                'document' => 'Unable to delete the physical PDF file.',
            ]);
        }

        // Delete the indexed pages and upload record.
        DB::transaction(function () use ($upload, $filename) {
            DB::table('documents')
                ->where('relative_path', $filename)
                ->delete();

            $upload->delete();
        });

        return redirect()
            ->route('documents.index')
            ->with('success', 'PDF deleted successfully.');
    }


    public function reprocess(PdfUpload $upload): RedirectResponse
    {
        if (in_array($upload->status, ['queued', 'processing'], true)) {
            return back()->withErrors([
                'document' => 'This PDF is already being processed.',
            ]);
        }

        $filename = $upload->stored_filename;

        if (! preg_match(
            '/^[0-9a-f-]{36}\.pdf$/i',
            $filename
        ) || ! is_file('/documents/' . $filename)) {
            return back()->withErrors([
                'document' => 'The PDF file is missing or invalid.',
            ]);
        }

        $upload->update([
            'status' => 'queued',
            'total_pages' => 0,
            'embedded_pages' => 0,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        ProcessUploadedPdf::dispatch($upload->id);

        return redirect()
            ->route('documents.index')
            ->with('success', 'PDF reprocessing has been queued.');
    }


}
