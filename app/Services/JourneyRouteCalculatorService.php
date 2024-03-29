<?php

namespace App\Services;

use App\Models\JourneyAttempt;
use App\Models\Waypoint;
use App\Models\WaypointDistance;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service to calculate optimal routes for journey attempts using the TSP solver and Bing Maps API.
 */
class JourneyRouteCalculatorService
{
    /**
     * Service for interacting with Bing Maps API.
     *
     * @var BingMapsService
     */
    protected $bingMapsService;

    /**
     * Initialize the service with dependencies.
     */
    public function __construct()
    {
        $this->bingMapsService = new BingMapsService();
    }

    /**
     * Calculates and updates the journey attempt with the shortest and longest routes.
     *
     * This method orchestrates the calculation process by building a distance matrix,
     * solving the TSP, and then updating the journey attempt with the results.
     *
     * @param   JourneyAttempt  $journeyAttempt  The journey attempt to calculate routes for.
     *
     * @return void
     */
    public function calculateJourneyRoutes(JourneyAttempt $journeyAttempt): void
    {
        // Retrieve the start waypoint associated with the journey attempt
        $startWaypoint = $journeyAttempt->startWaypoint;

        // Retrieve waypoints associated with the journey attempt excluding the start waypoint
        $waypoints = $journeyAttempt->waypoints()->where('id', '!=', $startWaypoint->id)->get();

        // Calculate the distance matrix for all waypoints including the start waypoint
        $distanceMatrix = $this->buildDistanceMatrix($waypoints, $startWaypoint);

        // Solve the TSP to find the best path
        $tspSolver = new TSPSolver($distanceMatrix, $startWaypoint->id);
        $tspSolver->solve();

        // Retrieve the longest and shortest paths from the TSP solver
        $longestPath  = $tspSolver->getLongestPath();
        $shortestPath = $tspSolver->getShortestPath();

        // Update the journey attempt with the calculated route details
        $journeyAttempt->update([
            'calculated'             => true,
            'shortest_path'          => $shortestPath['path'],
            'shortest_path_distance' => $shortestPath['distance'],
            'longest_path'           => $longestPath['path'],
            'longest_path_distance'  => $longestPath['distance'],
        ]);
    }

    /**
     * Builds a distance matrix for a set of waypoints, including the start waypoint,
     * to facilitate solving the Traveling Salesman Problem (TSP).
     *
     * Each waypoint's distance to every other waypoint is calculated and stored in the matrix,
     * which is then used by the TSP solver.
     *
     * @param   Collection  $waypoints      The collection of waypoints excluding the start.
     * @param   Waypoint    $startWaypoint  The starting waypoint.
     *
     * @return array The distance matrix as a two-dimensional array.
     */
    protected function buildDistanceMatrix(Collection $waypoints, Waypoint $startWaypoint): array
    {
        $distanceMatrix = [];

        // Include the start waypoint in the list to calculate distances
        $waypoints = $waypoints->prepend($startWaypoint);

        // Calculate distances between each pair of waypoints
        foreach ($waypoints as $origin) {
            foreach ($waypoints as $destination) {
                $distance = $this->fetchOrRetrieveDistance($origin, $destination);

                // Populate the distance matrix, avoiding redundancy
                if (! isset($distanceMatrix[ $origin->id ][ $destination->id ])) {
                    $this->saveWaypointDistance($origin->id, $destination->id, $distance);
                }

                $distanceMatrix[ $origin->id ][ $destination->id ] = $distance;
            }
        }

        return $distanceMatrix;
    }

    /**
     * Saves the distance between two waypoints in the database.
     *
     * If the distance does not already exist in the database, it creates a new record
     * in the WaypointDistance model to store the distance.
     *
     * @param   int               $originId       The ID of the origin waypoint.
     * @param   int               $destinationId  The ID of the destination waypoint.
     * @param   float|int|string  $distance       The distance between the waypoints.
     *
     * @return void
     */
    protected function saveWaypointDistance($originId, $destinationId, $distance): void
    {
        WaypointDistance::create([
            'origin_id'      => $originId,
            'destination_id' => $destinationId,
            'distance'       => $distance,
        ]);
    }

    /**
     * Attempts to fetch the distance between two waypoints from the database,
     * falling back to an API request if not found.
     *
     * This method optimizes distance retrieval by first checking if the distance
     * is already stored in the database, thereby reducing unnecessary API calls.
     *
     * @param   Waypoint  $origin       The origin waypoint.
     * @param   Waypoint  $destination  The destination waypoint.
     *
     * @return float|int|string The distance between the waypoints, or -1 on error.
     */
    public function fetchOrRetrieveDistance(Waypoint $origin, Waypoint $destination): float|int|string
    {
        if ($origin === $destination) {
            return 0;
        }

        try {
            $distance = $this->retrieveDistanceFromStorage($origin->id, $destination->id);
            if ($distance !== null) {
                return $distance;
            }

            $distance = $this->retrieveDistanceViaAPI($origin, $destination);
            $this->saveWaypointDistance($origin->id, $destination->id, $distance);

            return $distance;
        } catch (\Exception $e) {
            return - 1; // Indicate an error with -1
        }
    }

    /**
     * Retrieves a stored distance between two waypoints from the database, if available.
     *
     * This method queries the WaypointDistance model for a distance record between
     * the provided origin and destination waypoint IDs.
     *
     * @param   int  $originId       The ID of the origin waypoint.
     * @param   int  $destinationId  The ID of the destination waypoint.
     *
     * @return float|null The stored distance if found, or null otherwise.
     */
    protected function retrieveDistanceFromStorage($originId, $destinationId): ?float
    {
        $distanceRecord = WaypointDistance::where('origin_id', $originId)
                                          ->where('destination_id', $destinationId)
                                          ->first();

        return $distanceRecord ? $distanceRecord->distance : null;
    }

    /**
     * Fetches the distance between two waypoints using the Bing Maps API.
     *
     * This method is called when a distance is not found in the database and needs
     * to be retrieved from an external source. The retrieved distance is also stored
     * in the database for future reference.
     *
     * @param   Waypoint  $origin       The origin waypoint.
     * @param   Waypoint  $destination  The destination waypoint.
     *
     * @return float|int|string The distance between the waypoints.
     */
    protected function retrieveDistanceViaAPI(Waypoint $origin, Waypoint $destination): float|int|string
    {
        return $this->bingMapsService->getDistance(
            [ $origin->latitude, $origin->longitude ],
            [ $destination->latitude, $destination->longitude ]
        );
    }

    /**
     * Generates a Google Maps navigation link based on provided waypoint IDs.
     *
     * This function constructs a Google Maps navigation link that includes the provided waypoints.
     *
     * @param array $waypointIds An array of waypoint IDs.
     * @return array An array containing the names of waypoints and the constructed Google Maps URL.
     */
    public function generateGoogleMapsNavigationLink(array $waypointIds): array
    {
        // Check if the array of waypoint IDs is empty
        if (empty($waypointIds)) {
            // If empty, return an empty array for both text and link
            return [ 'text' => '', 'link' => '' ];
        }

        // Retrieve waypoints based on the provided IDs
        $waypoints = Waypoint::whereIn('id', $waypointIds)
                             ->orderByRaw('FIELD(id, ' . implode(',', $waypointIds) . ')')
                             ->get();

        // Construct the Google Maps URL with origin, destination, and waypoints
        $googleMapsURL = $this->buildGoogleMapsURL($waypoints);

        // Extract names of waypoints for display
        $waypointNames = $waypoints->pluck('name')->implode(', ');

        // Construct the HTML link for Google Maps
        $link = "<a style='--c-300:var(--primary-300);--c-400:var(--primary-400);--c-500:var(--primary-500);--c-600:var(--primary-600);' class='fi-link relative inline-flex items-center justify-center font-semibold outline-none transition duration-75 hover:underline focus-visible:underline fi-size-sm fi-link-size-sm gap-1 text-sm fi-color-custom text-custom-600 dark:text-custom-400 fi-ac-link-action' href='{$googleMapsURL}' target='_blank'>Google Map</a>";

        // Return an array containing waypoint names and the constructed link
        return [ 'text' => $waypointNames, 'link' => $link ];
    }

    /**
     * Constructs a Google Maps URL based on provided waypoints.
     *
     * This function constructs a Google Maps URL with the provided waypoints.
     *
     * @param \Illuminate\Support\Collection $waypoints A collection of Waypoint objects.
     * @return string The constructed Google Maps URL.
     */
    private function buildGoogleMapsURL($waypoints): string
    {
        // Extract the coordinates of origin, destination, and waypoints
        $origin = $waypoints->first();
        $destination = $waypoints->last();
        $waypoints = $waypoints->slice(1, -1);

        // Initialize the Google Maps URL with the API version parameter
        $url = "https://www.google.com/maps/dir/?api=1";

        // Add origin and destination coordinates to the URL
        $url .= "&origin=" . $origin->latitude . "," . $origin->longitude;
        $url .= "&destination=" . $destination->latitude . "," . $destination->longitude;

        // Add waypoint coordinates to the URL
        foreach ($waypoints as $waypoint) {
            $url .= "&waypoints=" . $waypoint->latitude . "," . $waypoint->longitude;
        }

        // Return the constructed Google Maps URL
        return $url;
    }
}
