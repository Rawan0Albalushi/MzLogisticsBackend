<?php

namespace App\Enums;

enum DocumentType: string
{
    case CommercialLicense = 'commercial_license';
    case Insurance = 'insurance';
    case VehicleRegistration = 'vehicle_registration';
    case DriverLicense = 'driver_license';
    case Identity = 'identity';
    case ProofOfDelivery = 'proof_of_delivery';
    case Other = 'other';
}
