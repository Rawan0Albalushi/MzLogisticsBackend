<?php

namespace App\Enums;

enum TruckType: string
{
    /** Default platform types seeded into truck_types. Live catalog is database-managed. */
    case Flatbed = 'flatbed';
    case Box = 'box';
    case Reefer = 'reefer';
    case Tanker = 'tanker';
    case Lowbed = 'lowbed';
    case Dump = 'dump';
    case Curtain = 'curtain';
}
