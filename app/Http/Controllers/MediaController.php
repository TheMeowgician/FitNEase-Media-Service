<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Models\VideoContent;
use App\Models\AudioContent;
use App\Events\MediaProcessingCompleted;
use App\Events\MediaUploadFailed;
use App\Services\ContentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:1048576',
            'entity_type' => 'nullable|string|max:50',
            'entity_id' => 'nullable|integer',
            'is_public' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $fileName = Str::uuid() . '_' . time() . '.' . $file->getClientOriginalExtension();
            $fileType = $this->determineFileType($file->getMimeType());

            $path = $file->storeAs("media/{$fileType}s", $fileName, 'public');

            $mediaFile = MediaFile::create([
                'file_name' => $fileName,
                'original_file_name' => $originalName,
                'file_path' => $path,
                'file_type' => $fileType,
                'file_size_bytes' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_by' => auth()->id() ?? 1,
                'entity_type' => $request->entity_type,
                'entity_id' => $request->entity_id,
                'is_public' => $request->boolean('is_public', false),
                'upload_status' => 'uploading'
            ]);

            if ($fileType === 'video' || $fileType === 'audio') {
                dispatch(function () use ($mediaFile) {
                    app('App\Services\MediaProcessingService')->processUploadedMedia($mediaFile->media_file_id);
                })->delay(now()->addSeconds(5));
            } else {
                $mediaFile->update(['upload_status' => 'ready']);
            }

            // Notify content service if this media is for an exercise
            if ($request->entity_type === 'exercise' && $request->entity_id) {
                $token = $request->bearerToken();
                if ($token) {
                    $contentService = new ContentService();
                    $contentService->notifyMediaUpload($token, $request->entity_id, $mediaFile->media_file_id);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully',
                'data' => [
                    'media_file_id' => $mediaFile->media_file_id,
                    'file_name' => $mediaFile->file_name,
                    'file_type' => $mediaFile->file_type,
                    'upload_status' => $mediaFile->upload_status
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Upload failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(int $fileId): JsonResponse
    {
        try {
            $mediaFile = MediaFile::with(['videoContent', 'audioContent', 'metadata'])
                ->where('media_file_id', $fileId)
                ->first();

            if (!$mediaFile) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'media_file_id' => $mediaFile->media_file_id,
                    'file_name' => $mediaFile->file_name,
                    'original_file_name' => $mediaFile->original_file_name,
                    'file_type' => $mediaFile->file_type,
                    'file_size' => $mediaFile->file_size_human,
                    'mime_type' => $mediaFile->mime_type,
                    'is_public' => $mediaFile->is_public,
                    'upload_status' => $mediaFile->upload_status,
                    'thumbnail_url' => $mediaFile->thumbnail_url,
                    'streaming_url' => $mediaFile->streaming_url,
                    'uploaded_at' => $mediaFile->uploaded_at,
                    'video_content' => $mediaFile->videoContent,
                    'audio_content' => $mediaFile->audioContent,
                    'metadata' => $mediaFile->metadata->pluck('value', 'metadata_key')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, int $fileId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'nullable|string|max:50',
            'entity_id' => 'nullable|integer',
            'is_public' => 'boolean',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $mediaFile = MediaFile::where('media_file_id', $fileId)->first();

            if (!$mediaFile) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found'
                ], 404);
            }

            $mediaFile->update($request->only([
                'entity_type',
                'entity_id',
                'is_public',
                'is_active'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'File updated successfully',
                'data' => $mediaFile->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Update failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(int $fileId): JsonResponse
    {
        try {
            $mediaFile = MediaFile::where('media_file_id', $fileId)->first();

            if (!$mediaFile) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found'
                ], 404);
            }

            if (Storage::exists($mediaFile->file_path)) {
                Storage::delete($mediaFile->file_path);
            }

            if ($mediaFile->thumbnail_path && Storage::exists($mediaFile->thumbnail_path)) {
                Storage::delete($mediaFile->thumbnail_path);
            }

            $mediaFile->delete();

            return response()->json([
                'success' => true,
                'message' => 'File deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Deletion failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function search(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:3',
            'type' => 'nullable|in:video,audio,image,document',
            'limit' => 'nullable|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = $request->input('query');
            $type = $request->input('type');
            $limit = $request->input('limit', 20);

            $mediaFiles = MediaFile::with(['videoContent', 'audioContent'])
                ->where('is_active', true)
                ->where('upload_status', 'ready')
                ->where(function ($q) use ($query) {
                    $q->where('original_file_name', 'LIKE', "%{$query}%")
                      ->orWhereHas('videoContent', function ($video) use ($query) {
                          $video->where('video_title', 'LIKE', "%{$query}%")
                                ->orWhere('video_description', 'LIKE', "%{$query}%");
                      })
                      ->orWhereHas('audioContent', function ($audio) use ($query) {
                          $audio->where('audio_title', 'LIKE', "%{$query}%")
                                ->orWhere('audio_description', 'LIKE', "%{$query}%");
                      });
                })
                ->when($type, function ($q) use ($type) {
                    $q->where('file_type', $type);
                })
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $mediaFiles->map(function ($file) {
                    return [
                        'media_file_id' => $file->media_file_id,
                        'file_name' => $file->original_file_name,
                        'file_type' => $file->file_type,
                        'thumbnail_url' => $file->thumbnail_url,
                        'streaming_url' => $file->streaming_url,
                        'title' => $file->videoContent?->video_title ?? $file->audioContent?->audio_title ?? $file->original_file_name,
                        'description' => $file->videoContent?->video_description ?? $file->audioContent?->audio_description
                    ];
                })
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Search failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function download(int $fileId): JsonResponse
    {
        try {
            $mediaFile = MediaFile::where('media_file_id', $fileId)
                ->where('is_active', true)
                ->first();

            if (!$mediaFile) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found'
                ], 404);
            }

            if (!Storage::exists($mediaFile->file_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found in storage'
                ], 404);
            }

            return response()->download(Storage::path($mediaFile->file_path), $mediaFile->original_file_name);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Download failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function thumbnail(int $fileId): JsonResponse
    {
        try {
            $mediaFile = MediaFile::where('media_file_id', $fileId)
                ->where('is_active', true)
                ->first();

            if (!$mediaFile || !$mediaFile->thumbnail_path) {
                return response()->json([
                    'success' => false,
                    'message' => 'Thumbnail not found'
                ], 404);
            }

            if (!Storage::exists($mediaFile->thumbnail_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Thumbnail not found in storage'
                ], 404);
            }

            return response()->file(Storage::path($mediaFile->thumbnail_path));

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Thumbnail retrieval failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function uploadProfilePicture(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|image|max:10240|mimes:jpeg,jpg,png,webp',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed. Please upload a valid image (JPEG, PNG, or WebP) under 10MB.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('file');
            $userId = $request->attributes->get('user_id') ?? auth()->id() ?? 0;
            $fileName = 'profile_' . $userId . '_' . time() . '.' . $file->getClientOriginalExtension();

            $path = $file->storeAs('media/images/profiles', $fileName, 'public');

            // Delete previous profile picture for this user to avoid orphaned files
            $existingMedia = MediaFile::where('entity_type', 'profile_picture')
                ->where('entity_id', $userId)
                ->first();

            if ($existingMedia) {
                if (Storage::disk('public')->exists($existingMedia->file_path)) {
                    Storage::disk('public')->delete($existingMedia->file_path);
                }
                $existingMedia->delete();
            }

            $mediaFile = MediaFile::create([
                'file_name' => $fileName,
                'original_file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => 'image',
                'file_size_bytes' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_by' => $userId,
                'entity_type' => 'profile_picture',
                'entity_id' => $userId,
                'is_public' => true,
                'upload_status' => 'ready'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile picture uploaded successfully',
                'data' => [
                    'media_file_id' => $mediaFile->media_file_id,
                    'file_name' => $mediaFile->file_name,
                    'file_path' => $path,
                    'url' => '/storage/' . $path,
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Profile picture upload failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function determineFileType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }

        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        return 'document';
    }
}
