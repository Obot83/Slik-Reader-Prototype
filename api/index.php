<?php
// Vercel Function entry point.
//
// Vercel's `functions` config in vercel.json only matches source files that
// live under the /api directory — it rejected "public/index.php" directly
// (error: "doesn't match any Serverless Functions inside the `api`
// directory"). This thin wrapper satisfies that requirement while leaving
// the actual application entry point (public/index.php) and all of its
// __DIR__-relative path logic completely untouched — PHP's __DIR__ inside
// the required file still resolves to its own location (public/), not here.
require __DIR__ . '/../public/index.php';
