<?php

namespace App\DTO;

class SlikMetadataDto implements \JsonSerializable {
    public ?string $no_laporan = null;
    public ?string $tanggal_permintaan = null;
    public ?string $no_identitas = null;
    public ?string $nama_debitur = null;
    public ?string $tempat_lahir = null;
    public ?string $tanggal_lahir = null;
    public ?string $jenis_kelamin = null;
    public ?string $npwp = null;
    public ?string $alamat = null;
    public ?string $posisi_data_terakhir = null;

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
