<?php

namespace App\DTO;

class SlikSummaryDto implements \JsonSerializable {
    public float $plafon_efektif_total = 0;
    public float $baki_debet_total = 0;
    public float $utilisasi_plafon = 0;
    public string $kualitas_terburuk = "1 - Lancar";
    public ?string $bulan_kualitas_terburuk = null;
    public int $kredit_bank_umum = 0;
    public int $kredit_bpr = 0;
    public int $kredit_lp = 0;
    public int $kredit_lainnya = 0;

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
