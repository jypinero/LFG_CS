<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEndDateColumnsToEventsAndBookingsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Events table
        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                if (! Schema::hasColumn('events', 'end_date')) {
                    // place after 'date' if exists otherwise append
                    if (Schema::hasColumn('events', 'date')) {
                        $table->date('end_date')->nullable()->after('date');
                    } else {
                        $table->date('end_date')->nullable();
                    }
                }
                if (! Schema::hasColumn('events', 'end_date_start_time')) {
                    if (Schema::hasColumn('events', 'end_date')) {
                        $table->time('end_date_start_time')->nullable()->after('end_date');
                    } else {
                        $table->time('end_date_start_time')->nullable();
                    }
                }
                if (! Schema::hasColumn('events', 'end_date_end_time')) {
                    if (Schema::hasColumn('events', 'end_date_start_time')) {
                        $table->time('end_date_end_time')->nullable()->after('end_date_start_time');
                    } else {
                        $table->time('end_date_end_time')->nullable();
                    }
                }
            });
        }

        // Bookings table
        if (Schema::hasTable('bookings')) {
            Schema::table('bookings', function (Blueprint $table) {
                if (! Schema::hasColumn('bookings', 'end_date')) {
                    if (Schema::hasColumn('bookings', 'date')) {
                        $table->date('end_date')->nullable()->after('date');
                    } else {
                        $table->date('end_date')->nullable();
                    }
                }
                if (! Schema::hasColumn('bookings', 'end_date_start_time')) {
                    if (Schema::hasColumn('bookings', 'end_date')) {
                        $table->time('end_date_start_time')->nullable()->after('end_date');
                    } else {
                        $table->time('end_date_start_time')->nullable();
                    }
                }
                if (! Schema::hasColumn('bookings', 'end_date_end_time')) {
                    if (Schema::hasColumn('bookings', 'end_date_start_time')) {
                        $table->time('end_date_end_time')->nullable()->after('end_date_start_time');
                    } else {
                        $table->time('end_date_end_time')->nullable();
                    }
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                if (Schema::hasColumn('events', 'end_date_end_time')) {
                    $table->dropColumn('end_date_end_time');
                }
                if (Schema::hasColumn('events', 'end_date_start_time')) {
                    $table->dropColumn('end_date_start_time');
                }
                if (Schema::hasColumn('events', 'end_date')) {
                    $table->dropColumn('end_date');
                }
            });
        }

        if (Schema::hasTable('bookings')) {
            Schema::table('bookings', function (Blueprint $table) {
                if (Schema::hasColumn('bookings', 'end_date_end_time')) {
                    $table->dropColumn('end_date_end_time');
                }
                if (Schema::hasColumn('bookings', 'end_date_start_time')) {
                    $table->dropColumn('end_date_start_time');
                }
                if (Schema::hasColumn('bookings', 'end_date')) {
                    $table->dropColumn('end_date');
                }
            });
        }
    }
}
