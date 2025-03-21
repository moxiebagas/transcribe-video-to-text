<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AudioTranscriptionController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/', [AudioTranscriptionController::class, 'showUploadForm'])->name('upload.form');
Route::post('/process-video', [AudioTranscriptionController::class, 'processVideoAndTranscribe'])->name('process.video');