<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->paginate((int) $request->integer('per_page', 20));

        return ApiResponse::success($notifications);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $item = $request->user()->notifications()->where('id', $notification)->firstOrFail();
        $item->markAsRead();

        return ApiResponse::success($item, 'Notification marked as read.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return ApiResponse::success(null, 'All notifications marked as read.');
    }
}
