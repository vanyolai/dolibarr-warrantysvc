<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * Compatibility entry point for Dolibarr CommonObject document generation.
 *
 * CommonObject::commonGenerateDocument() resolves generators with the
 * pdf_<model>.modules.php naming convention. Keep the actual generator in its
 * existing file and expose it through the standard loader name.
 */
require_once __DIR__.'/pdf_svcrequest_standard.php';
