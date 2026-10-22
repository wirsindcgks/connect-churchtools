<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Api;

use RuntimeException;

/**
 * ChurchTools ist gerade nicht erreichbar - nicht nur ein Endpunkt, sondern
 * die ganze Instanz: kein Netzweg (Zeitueberschreitung, DNS, TLS) oder eine
 * Antwort mit 502, 503 oder 504 (Wartung, Sperre, ueberlasteter Proxy).
 *
 * Eine eigene Klasse, damit ein Lauf nach dem ersten solchen Fehlschlag
 * abbrechen kann, statt jeden weiteren Aufruf einzeln scheitern zu lassen und
 * jeden einzeln zu protokollieren (Anlass: die Sperre aller Instanzen am
 * 2026-10-21 wegen einer PHP-Luecke - ein Lauf schrieb dieselbe lange Meldung
 * dreimal ins Protokoll, einmal davon als Info).
 *
 * Ein 500 gehoert bewusst nicht dazu: Er kommt bei ChurchTools auch von einem
 * einzelnen fehlerhaften Endpunkt, und der soll den Rest des Laufs nicht
 * mitnehmen.
 */
final class UnavailableException extends RuntimeException
{
    /** @var list<int> */
    public const STATUS_CODES = [502, 503, 504];
}
