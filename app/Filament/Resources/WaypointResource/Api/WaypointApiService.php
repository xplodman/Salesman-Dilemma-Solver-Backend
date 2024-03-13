<?php
namespace App\Filament\Resources\WaypointResource\Api;

use Rupadana\ApiService\ApiService;
use App\Filament\Resources\WaypointResource;
use Illuminate\Routing\Router;


class WaypointApiService extends ApiService
{
    protected static string | null $resource = WaypointResource::class;

    public static function handlers() : array
    {
        return [
            Handlers\CreateHandler::class,
            Handlers\UpdateHandler::class,
            Handlers\DeleteHandler::class,
            Handlers\PaginationHandler::class,
            Handlers\DetailHandler::class
        ];

    }
}
