<?php

namespace APP\plugins\generic\scholar9Review;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

class Scholar9ReviewSchemaMigration extends Migration
{
    public function up(): void
    {
        Scholar9ReviewDAO::install();
    }

    public function down(): void
    {
        Schema::dropIfExists('scholar9_reviews');
    }
}
