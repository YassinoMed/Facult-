<?php

namespace App\Modules\Evaluation\Exceptions;

use RuntimeException;

/**
 * Interruption coopérative d'un traitement d'affectation annulé entre
 * deux épreuves. Technique interne (jamais exposée telle quelle) :
 * traverse `appliquerSessionParLots` sans être comptée en avertissement.
 */
class RunAnnuleException extends RuntimeException {}
