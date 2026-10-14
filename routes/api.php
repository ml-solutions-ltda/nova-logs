<?php
use Illuminate\Support\Facades\Route;
use MlSolutions\NovaLogViewer\Http\Controllers\NativeLogController;
Route::middleware('throttle:120,1,nova-native-logs:')->group(function () {
    Route::get('files',[NativeLogController::class,'files']);
    Route::get('entries',[NativeLogController::class,'entries']);
    Route::get('entries/{reference}',[NativeLogController::class,'show'])->where('reference','[a-f0-9]{64}');
    Route::get('files/{id}/download',[NativeLogController::class,'download'])->where('id','[a-f0-9]{64}');
    // Old expensive parsing contracts are explicitly retired. Updated assets use cursors.
    Route::get('get_chart_data', fn () => abort(410, 'Atualize os assets: resumos agora são limitados à janela de um arquivo.'));
    Route::get('get_list_logs', fn () => abort(410, 'Use /files para a descoberta limitada.'));
    Route::get('get/{date}/{level}', fn () => abort(410, 'Use /entries com cursor e arquivo.'));
    Route::get('download/{date}', function (string $date) {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date), 404);
        $reader = app(\MlSolutions\NovaLogViewer\Support\BoundedLogReader::class);
        $file = collect($reader->files()['data'])->firstWhere('name','laravel-'.$date.'.log');
        abort_unless($file,404);
        return app(NativeLogController::class)->download($file['id']);
    });
    Route::delete('delete', fn () => abort(410, 'Use o procedimento operacional autorizado de retenção.'));
});
