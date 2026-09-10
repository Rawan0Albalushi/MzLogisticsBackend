<?php

namespace App\Enums;

enum TruckType: string
{
    case Flatbed = 'flatbed';
    case Box = 'box';
    case Reefer = 'reefer';
    case Tanker = 'tanker';
    case Lowbed = 'lowbed';
    case Dump = 'dump';
    case Curtain = 'curtain';
}
