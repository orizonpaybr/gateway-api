<?php

namespace App\DTO;

class ApiDepositResponseDTO
{
    public function __construct(
        public string $idTransaction,
        public string $qrcode,
        public string $qr_code_image_url
    ) {}
}