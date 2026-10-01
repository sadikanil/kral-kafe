<?php

namespace App\Contracts;

/**
 * Kurum geneli deneme PDF'ini okuyan yapay zeka (1 Ekim 2026).
 *
 * Iki adim, her biri tek istek ve Vercel'in 60 sn sinirina sigacak kadar
 * kucuk: once dizin (sinav, katilimcilar, ogrenci listesi ve karne
 * sayfalari), sonra her ogrencinin karnesi ayri ayri. Sekil
 * App\Services\ExamImport\ExamPdfSchema'da; iki saglayici da ayni semayi
 * kullanir, cikti ayni bicimde doner.
 *
 * Basarisizlikta ExamPdfReadException atar (yoneticiye gosterilecek metin).
 */
interface ExamPdfReader
{
    /** 'anthropic' | 'openai' - kayitta hangi saglayicinin okudugu. */
    public function provider(): string;

    /**
     * @param  array<string,string>  $dersler  kod => ad (yalnizca bu denemenin turu)
     * @return array<string,mixed>  ExamPdfSchema::index() bicimi
     */
    public function readIndex(string $pdf, array $dersler): array;

    /**
     * @param  array<string,string>  $dersler
     * @return array<string,mixed>  ExamPdfSchema::card() bicimi
     */
    public function readCard(string $pdf, int $sayfa, string $ad, array $dersler): array;
}
