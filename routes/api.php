<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned the "api" middleware group.
|
*/

// -------------------------------------------------------------------------
// Public routes (no authentication required)
// -------------------------------------------------------------------------

Route::post('/auth/login',    [App\Http\Controllers\AuthController::class, 'login']);
Route::post('/auth/token',    [App\Http\Controllers\AuthController::class, 'login']); // alias FastAPI
Route::post('/auth/register', [App\Http\Controllers\AuthController::class, 'register']);

// Health (public alias)
Route::get('/health', [App\Http\Controllers\SystemController::class, 'health']);

// -------------------------------------------------------------------------
// Protected routes (Sanctum token authentication required)
// -------------------------------------------------------------------------

Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/auth/logout', [App\Http\Controllers\AuthController::class, 'logout']);
    Route::get('/auth/me',          [App\Http\Controllers\AuthController::class, 'me']);
    Route::get('/auth/users/me',    [App\Http\Controllers\AuthController::class, 'me']); // alias FastAPI

    // Users
    Route::get('/users',                       [App\Http\Controllers\UserController::class, 'index']);
    Route::post('/users',                      [App\Http\Controllers\UserController::class, 'store']);
    Route::put('/users/{id}',                  [App\Http\Controllers\UserController::class, 'update']);
    Route::delete('/users/{id}',               [App\Http\Controllers\UserController::class, 'destroy']);
    Route::put('/users/{id}/password',         [App\Http\Controllers\UserController::class, 'updatePassword']);

    // Accounts
    Route::apiResource('accounts', App\Http\Controllers\AccountController::class);
    Route::post('/accounts/{id}/test-connection', [App\Http\Controllers\AccountController::class, 'testConnection']);
    Route::post('/accounts/{id}/restore',         [App\Http\Controllers\AccountController::class, 'restore']);

    // Admin: gestión de cuentas de todos los usuarios (solo admin)
    Route::get('/admin/accounts',        [App\Http\Controllers\AccountController::class, 'indexAdmin']);
    Route::post('/admin/accounts',       [App\Http\Controllers\AccountController::class, 'storeForUser']);
    Route::delete('/admin/accounts/{id}', [App\Http\Controllers\AccountController::class, 'destroyAdmin']);

    // Messages
    Route::get('/messages',                       [App\Http\Controllers\MessageController::class, 'index']);
    Route::get('/messages/unread-counts',         [App\Http\Controllers\MessageController::class, 'unreadCounts']);
    Route::get('/messages/{id}',                  [App\Http\Controllers\MessageController::class, 'show']);
    Route::put('/messages/{id}/read',             [App\Http\Controllers\MessageController::class, 'markRead']);
    Route::put('/messages/{id}/flags',            [App\Http\Controllers\MessageController::class, 'updateFlags']); // alias FastAPI
    Route::patch('/messages/{id}',                [App\Http\Controllers\MessageController::class, 'patch']);       // alias FastAPI
    Route::delete('/messages/{id}',               [App\Http\Controllers\MessageController::class, 'destroy']);
    Route::patch('/messages/mark-all-read',       [App\Http\Controllers\MessageController::class, 'markAllRead']);
    Route::put('/messages/{id}/classify',         [App\Http\Controllers\ClassifyController::class, 'updateLabel']);
    Route::get('/classifications/{messageId}',    [App\Http\Controllers\ClassifyController::class, 'show']);

    // Sync
    Route::post('/sync/start',               [App\Http\Controllers\SyncController::class, 'start']);
    Route::post('/sync/stream',              [App\Http\Controllers\SyncController::class, 'stream']);
    Route::get('/sync/status',               [App\Http\Controllers\SyncController::class, 'status']);
    Route::post('/sync/resync-bodies',       [App\Http\Controllers\SyncController::class, 'resyncBodies']);
    Route::post('/sync/resync-attachments',  [App\Http\Controllers\SyncController::class, 'resyncAttachments']);

    // Classify
    Route::post('/classify/{messageId}',            [App\Http\Controllers\ClassifyController::class, 'classify']);
    Route::post('/classify/pending/{accountId}',    [App\Http\Controllers\ClassifyController::class, 'classifyPending']);

    // Send email
    Route::post('/send', [App\Http\Controllers\SendController::class, 'send']);

    // Attachments
    Route::get('/attachments/{id}/download', [App\Http\Controllers\AttachmentController::class, 'download']);

    // Categories
    Route::apiResource('categories', App\Http\Controllers\CategoryController::class);

    // Service Whitelist
    Route::get('/whitelist', [App\Http\Controllers\WhitelistController::class, 'index']);
    Route::post('/whitelist', [App\Http\Controllers\WhitelistController::class, 'store']);
    Route::delete('/whitelist/{id}', [App\Http\Controllers\WhitelistController::class, 'destroy']);

    // Sender Rules
    Route::apiResource('rules', App\Http\Controllers\RulesController::class);

    // AI Configuration
    Route::get('/ai-config',         [App\Http\Controllers\AiConfigController::class, 'show']);
    Route::put('/ai-config',         [App\Http\Controllers\AiConfigController::class, 'update']);
    Route::get('/ai-config/models',  [App\Http\Controllers\AiConfigController::class, 'models']);

    // AI Actions
    Route::get('/ai/status',          [App\Http\Controllers\AiController::class, 'status']);
    Route::post('/ai/generate_reply', [App\Http\Controllers\AiController::class, 'generateReply']);
    Route::post('/ai/test',           [App\Http\Controllers\AiController::class, 'testConnection']);

    // System
    Route::get('/system/health', [App\Http\Controllers\SystemController::class, 'health']);
    Route::get('/audit-logs', [App\Http\Controllers\SystemController::class, 'auditLogs']);
});
