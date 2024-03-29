<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JourneyAttemptResource\Api\Transformers\JourneyAttemptTransformer;
use App\Filament\Resources\JourneyAttemptResource\Pages;
use App\Filament\Resources\JourneyAttemptResource\RelationManagers;
use App\Models\JourneyAttempt;
use App\Models\User;
use App\Models\Waypoint;
use App\Services\JourneyRouteCalculatorService;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class JourneyAttemptResource extends Resource {
    protected static ?string $model = JourneyAttempt::class;

    protected static ?string $navigationIcon = 'heroicon-m-map';

    protected static ?int $navigationSort = 2;

    public static function form( Form $form ): Form {
        return $form
            ->schema( [
                TextInput::make( 'name' )
                         ->required()
                         ->maxLength( 255 ),
                Select::make( 'start_waypoint_id' )
                      ->label( 'Start Waypoint' )
                      ->options( function ( JourneyAttempt $journeyAttempt ) {
                          return Waypoint::where( 'user_id', auth()->user()->id )->where( 'journey_attempt_id', $journeyAttempt->id )->pluck( 'name', 'id' );
                      } )
                      ->default( 'start_waypoint_id' )
                      ->nullable()
                      ->visibleOn( 'edit' )
                      ->searchable(),
                TextInput::make( 'shortest_path' )
                         ->helperText( function ( JourneyAttempt $journeyAttempt ) {
                             if ( empty( $journeyAttempt->shortest_path ) ) {
                                 return '';
                             }

                             $googleMapsInfo = ( new JourneyRouteCalculatorService() )->generateGoogleMapsNavigationLink( $journeyAttempt->shortest_path );

                             return new HtmlString( 'Route link: ' . $googleMapsInfo['link'] );
                         } )
                         ->visible( function ( JourneyAttempt $journeyAttempt ) {
                             return $journeyAttempt->calculated;
                         } )
                         ->formatStateUsing( function ( JourneyAttempt $journeyAttempt ): string {
                             if ( empty( $journeyAttempt->shortest_path ) ) {
                                 return '';
                             }

                             $googleMapsInfo = ( new JourneyRouteCalculatorService() )->generateGoogleMapsNavigationLink( $journeyAttempt->shortest_path );

                             return $googleMapsInfo['text'];
                         } )
                         ->disabled(),
                TextInput::make( 'shortest_path_distance' )
                         ->label( 'Shortest Path Distance (Kilometers)' )
                         ->visible( function ( JourneyAttempt $journeyAttempt ) {
                             return $journeyAttempt->calculated;
                         } )
                         ->disabled(),
            ] );
    }

    public static function table( Table $table ): Table {
        return $table
            ->columns( [
                Tables\Columns\TextColumn::make( 'name' )->searchable()
                                         ->toggleable(),
                Tables\Columns\TextColumn::make( 'startWaypoint.name' )
                                         ->label( 'Start Waypoint' )
                                         ->searchable()
                                         ->toggleable()
                                         ->wrap()
                                         ->sortable(),
                Tables\Columns\IconColumn::make( 'calculated' )->boolean()
                                         ->toggleable(),
                Tables\Columns\TextColumn::make( 'shortest_path' )
                                         ->disabledClick( function ( JourneyAttempt $journeyAttempt ) {
                                             return ! empty( $journeyAttempt->shortest_path );
                                         } )
                                         ->wrap()
                                         ->label( 'Shortest Path' )
                                         ->formatStateUsing( function ( string $state, JourneyAttempt $journeyAttempt ): string {
                                             if ( empty( $journeyAttempt->shortest_path ) ) {
                                                 return '';
                                             }

                                             $googleMapsInfo = ( new JourneyRouteCalculatorService() )->generateGoogleMapsNavigationLink( $journeyAttempt->shortest_path );

                                             return $googleMapsInfo['text'] . ' - ' . $googleMapsInfo['link'];
                                         } )
                                         ->toggleable()
                                         ->html(),
                Tables\Columns\TextColumn::make( 'user.name' )
                                         ->visible( function () {
                                             return auth()->user()->hasRole( 'admin' );
                                         } )
                                         ->toggleable()
                                         ->sortable(),
                Tables\Columns\TextColumn::make( 'deleted_at' )
                                         ->dateTime()
                                         ->sortable()
                                         ->toggleable( isToggledHiddenByDefault: true ),
                Tables\Columns\TextColumn::make( 'created_at' )
                                         ->dateTime()
                                         ->sortable()
                                         ->toggleable( isToggledHiddenByDefault: true ),
                Tables\Columns\TextColumn::make( 'updated_at' )
                                         ->dateTime()
                                         ->sortable()
                                         ->toggleable( isToggledHiddenByDefault: true ),
            ] )
            ->filters( [
                TrashedFilter::make(),
                SelectFilter::make( 'user' )
                            ->visible( function () {
                                return auth()->user()->hasRole( 'admin' );
                            } )
                            ->relationship( 'user', 'name' ),
                Tables\Filters\TernaryFilter::make('calculated')
            ] )
            ->actions( [
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\ForceDeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ] )
            ->bulkActions( [
                Tables\Actions\BulkActionGroup::make( [
                    Tables\Actions\DeleteBulkAction::make(),
                ] ),
            ] )
            ->modifyQueryUsing( fn( Builder $query ) => $query->withoutGlobalScopes( [
                SoftDeletingScope::class,
            ] ) );
    }

    public static function getRelations(): array {
        return [
            RelationManagers\WaypointsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder {
        if ( auth()->user()->hasRole( 'admin' ) ) {
            return parent::getEloquentQuery();
        }

        return parent::getEloquentQuery()->where( 'user_id', auth()->user()->id );
    }

    public static function getPages(): array {
        return [
            'index'  => Pages\ListJourneyAttempts::route( '/' ),
            'create' => Pages\CreateJourneyAttempt::route( '/create' ),
            'edit'   => Pages\EditJourneyAttempt::route( '/{record}/edit' ),
        ];
    }
}
