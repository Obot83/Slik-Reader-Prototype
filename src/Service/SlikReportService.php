<?php

namespace App\Service;

class SlikReportService {

    public function generateReportHtml(array $result): string {
        $meta = $result['metadata'] ?? [];
        $ringkasan = $result['ringkasan'] ?? [];
        $fasilitas = $result['fasilitas'] ?? [];
        $dataPokok = $result['data_pokok'] ?? [];

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>SLIK iDeb Report - ' . htmlspecialchars($result['result_id'] ?? '') . '</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #1e293b; margin: 20px; line-height: 1.5; }
        .header { border-bottom: 2px solid #0f172a; padding-bottom: 10px; margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 18px; color: #0f172a; }
        .header p { margin: 2px 0; color: #64748b; font-size: 11px; }
        .section-title { font-size: 14px; font-weight: bold; background: #f1f5f9; padding: 6px 10px; border-left: 4px solid #2563eb; margin: 20px 0 10px 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        th { background: #f8fafc; font-weight: 600; color: #334155; }
        .grid-2 { display: flex; flex-wrap: wrap; gap: 10px; }
        .grid-col { flex: 1; min-width: 45%; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 11px; }
        .badge-green { background: #dcfce7; color: #15803d; }
        .badge-amber { background: #fef3c7; color: #b45309; }
        .badge-red { background: #fee2e2; color: #b91c1c; }
        .text-right { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>LAPORAN INFORMASI DEBITUR (iDeb) - SLIK OJK</h1>
        <p>Sistem TOP Operator | Status: ' . htmlspecialchars($result['status'] ?? 'PENDING') . ' | ID Result: ' . htmlspecialchars($result['result_id'] ?? '') . '</p>
    </div>

    <div class="section-title">SECTION 1: DATA POKOK & IDENTITAS NASABAH</div>
    <table>
        <tr>
            <th width="20%">Nama Debitur</th>
            <td width="30%"><strong>' . htmlspecialchars($meta['nama_debitur'] ?? '-') . '</strong></td>
            <th width="20%">No. Laporan</th>
            <td width="30%">' . htmlspecialchars($meta['no_laporan'] ?? '-') . '</td>
        </tr>
        <tr>
            <th>No. Identitas (NIK)</th>
            <td>' . htmlspecialchars($meta['no_identitas'] ?? '-') . '</td>
            <th>Tanggal Permintaan</th>
            <td>' . htmlspecialchars($meta['tanggal_permintaan'] ?? '-') . '</td>
        </tr>
        <tr>
            <th>NPWP</th>
            <td>' . htmlspecialchars($meta['npwp'] ?? '-') . '</td>
            <th>Posisi Data Terakhir</th>
            <td>' . htmlspecialchars($meta['posisi_data_terakhir'] ?? '-') . '</td>
        </tr>
        <tr>
            <th>Jenis Kelamin</th>
            <td>' . htmlspecialchars($meta['jenis_kelamin'] ?? '-') . '</td>
            <th>Tempat, Tanggal Lahir</th>
            <td>' . htmlspecialchars(($meta['tempat_lahir'] ?? '') . ', ' . ($meta['tanggal_lahir'] ?? '')) . '</td>
        </tr>
        <tr>
            <th>Alamat Sesuai KTP</th>
            <td colspan="3">' . htmlspecialchars($meta['alamat'] ?? '-') . '</td>
        </tr>
    </table>

    <div class="section-title">SECTION 2: RINGKASAN EKSEKUTIF FINANSIAL</div>
    <table>
        <tr>
            <th width="25%">Plafon Efektif Total</th>
            <td width="25%">Rp ' . number_format((float)($ringkasan['plafon_efektif_total'] ?? 0), 0, ',', '.') . '</td>
            <th width="25%">Kualitas Terburuk</th>
            <td width="25%"><span class="badge badge-green">' . htmlspecialchars($ringkasan['kualitas_terburuk'] ?? '1 - Lancar') . '</span></td>
        </tr>
        <tr>
            <th>Baki Debet Total</th>
            <td>Rp ' . number_format((float)($ringkasan['baki_debet_total'] ?? 0), 0, ',', '.') . '</td>
            <th>Bulan Kualitas Terburuk</th>
            <td>' . htmlspecialchars($ringkasan['bulan_kualitas_terburuk'] ?? '-') . '</td>
        </tr>
        <tr>
            <th>Utilisasi Plafon</th>
            <td>' . round(((float)($ringkasan['utilisasi_plafon'] ?? 0)) * 100, 1) . '%</td>
            <th>Fasilitas Kredit Bank Umum</th>
            <td>' . (int)($ringkasan['kredit_bank_umum'] ?? 0) . ' Fasilitas</td>
        </tr>
        <tr>
            <th>Fasilitas Kredit BPR</th>
            <td>' . (int)($ringkasan['kredit_bpr'] ?? 0) . ' Fasilitas</td>
            <th>Fasilitas Kredit Lembaga Pembiayaan</th>
            <td>' . (int)($ringkasan['kredit_lp'] ?? 0) . ' Fasilitas</td>
        </tr>
    </table>

    <div class="section-title">SECTION 3: DATA POKOK DEBITUR (INSTANSI PELAPOR)</div>
    <table>
        <thead>
            <tr>
                <th>Pelapor</th>
                <th>Tanggal Update</th>
                <th>Alamat</th>
                <th>Pekerjaan</th>
            </tr>
        </thead>
        <tbody>';
        foreach ($dataPokok as $dp) {
            $html .= '<tr>
                <td>' . htmlspecialchars($dp['pelapor'] ?? '-') . '</td>
                <td>' . htmlspecialchars($dp['tanggal_update'] ?? '-') . '</td>
                <td>' . htmlspecialchars($dp['alamat'] ?? '-') . '</td>
                <td>' . htmlspecialchars($dp['pekerjaan'] ?? '-') . '</td>
            </tr>';
        }
        $html .= '</tbody>
    </table>

    <div class="section-title">SECTION 4: RINCIAN FASILITAS PEMBIAYAAN</div>
    <table>
        <thead>
            <tr>
                <th>Pelapor</th>
                <th>Cabang</th>
                <th>Jenis Fasilitas</th>
                <th class="text-right">Plafon (Rp)</th>
                <th class="text-right">Baki Debet (Rp)</th>
                <th>Kualitas</th>
                <th>Kondisi</th>
            </tr>
        </thead>
        <tbody>';
        foreach ($fasilitas as $fas) {
            $html .= '<tr>
                <td>' . htmlspecialchars($fas['pelapor'] ?? '-') . '</td>
                <td>' . htmlspecialchars($fas['cabang'] ?? '-') . '</td>
                <td>' . htmlspecialchars($fas['jenis_fasilitas'] ?? '-') . '</td>
                <td class="text-right">' . number_format((float)($fas['plafon'] ?? 0), 0, ',', '.') . '</td>
                <td class="text-right">' . number_format((float)($fas['baki_debet'] ?? 0), 0, ',', '.') . '</td>
                <td><span class="badge badge-green">' . htmlspecialchars($fas['kualitas'] ?? '-') . '</span></td>
                <td>' . htmlspecialchars($fas['kondisi'] ?? '-') . '</td>
            </tr>';
        }
        $html .= '</tbody>
    </table>
</body>
</html>';
        return $html;
    }

    public function generateReportCsv(array $result): string {
        $meta = $result['metadata'] ?? [];
        $ringkasan = $result['ringkasan'] ?? [];
        $fasilitas = $result['fasilitas'] ?? [];

        $out = fopen('php://memory', 'r+');
        fputcsv($out, ['LAPORAN INFORMASI DEBITUR (iDeb) - SLIK OJK']);
        fputcsv($out, ['ID Result', $result['result_id'] ?? '', 'Lead ID', $result['lead_id'] ?? '', 'Status', $result['status'] ?? '']);
        fputcsv($out, []);
        fputcsv($out, ['SECTION 1: DATA POKOK & IDENTITAS NASABAH']);
        fputcsv($out, ['No Laporan', $meta['no_laporan'] ?? '']);
        fputcsv($out, ['Nama Debitur', $meta['nama_debitur'] ?? '']);
        fputcsv($out, ['No Identitas (NIK)', $meta['no_identitas'] ?? '']);
        fputcsv($out, ['NPWP', $meta['npwp'] ?? '']);
        fputcsv($out, ['Tempat, Tgl Lahir', ($meta['tempat_lahir'] ?? '') . ', ' . ($meta['tanggal_lahir'] ?? '')]);
        fputcsv($out, ['Jenis Kelamin', $meta['jenis_kelamin'] ?? '']);
        fputcsv($out, ['Alamat', $meta['alamat'] ?? '']);
        fputcsv($out, []);
        fputcsv($out, ['SECTION 2: RINGKASAN EKSEKUTIF FINANSIAL']);
        fputcsv($out, ['Plafon Efektif Total', $ringkasan['plafon_efektif_total'] ?? 0]);
        fputcsv($out, ['Baki Debet Total', $ringkasan['baki_debet_total'] ?? 0]);
        fputcsv($out, ['Utilisasi Plafon', $ringkasan['utilisasi_plafon'] ?? 0]);
        fputcsv($out, ['Kualitas Terburuk', $ringkasan['kualitas_terburuk'] ?? '']);
        fputcsv($out, []);
        fputcsv($out, ['SECTION 4: RINCIAN FASILITAS']);
        fputcsv($out, ['Pelapor', 'Cabang', 'Jenis Fasilitas', 'Plafon', 'Baki Debet', 'Kualitas', 'Kondisi']);
        foreach ($fasilitas as $f) {
            fputcsv($out, [
                $f['pelapor'] ?? '',
                $f['cabang'] ?? '',
                $f['jenis_fasilitas'] ?? '',
                $f['plafon'] ?? 0,
                $f['baki_debet'] ?? 0,
                $f['kualitas'] ?? '',
                $f['kondisi'] ?? ''
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}
