<?php

namespace App\Modules\Evaluation\Exceptions;

use DomainException;

/**
 * Refus explicite d'un regroupement virtuel d'examen (§2.1).
 *
 * Le refus est toujours motivé et remonté au demandeur : aucune
 * correspondance académique approximative n'est jamais appliquée
 * silencieusement.
 */
class RegroupementVirtuelRefuse extends DomainException {}
