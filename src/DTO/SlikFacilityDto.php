<?php

namespace App\DTO;

class SlikFacilityDto implements \JsonSerializable {
    public ?string $pelapor = null;
    public ?string $cabang = null;
    public ?string $jenis_fasilitas = null;
    public ?float $plafon = 0;
    public ?float $baki_debet = 0;
    public ?string $kualitas = null;
    public ?string $kondisi = null;
    public ?string $tgl_kondisi = null;
    public ?string $tgl_mulai = null;
    public ?string $tgl_jatuh_tempo = null;
    public ?string $update = null;

    public function __construct(array $data = []) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function jsonSerialize(): array {
        return get_object_vars($this);
    }
}
