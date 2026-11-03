<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Match exact seed fixtures only; never hide a customer's unrelated review.
        foreach ([
            ['noble', 'Aarushi M.', 'One spray in the morning and I still get compliments at dinner. Refined without shouting.'],
            ['blaze', 'Rohan K.', 'Bold opening, warm dry-down. Wore it for a date night and she asked what it was within minutes.'],
            ['petals', 'Sneha P.', 'Soft florals that stay from morning to midnight. The bottle and box feel truly premium for the price.'],
            ['pearls', 'Ishaan V.', 'Clean, sophisticated, perfect for office. Wish I had ordered the 100ML.'],
        ] as [$slug, $author, $body]) {
            DB::table('reviews')->where('product_slug', $slug)->where('author', $author)->where('body', $body)->update(['active' => false]);
        }
    }

    public function down(): void
    { /* Demo reviews must not be republished on rollback. */
    }
};
