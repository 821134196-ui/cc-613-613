<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 学生资格实体：学籍信息与在读状态。
 * 与学生证（student_cards）分离管理——休学/恢复只作用于本实体。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_no', 32)->unique()->comment('完整学号（仅教务/本人登录使用，不对窗口展示）');
            $table->string('name');
            $table->string('college')->comment('院系');
            $table->string('major')->nullable()->comment('专业');
            // active=在读 suspended=休学 graduated=毕业
            $table->string('enrollment_status', 16)->default('active');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();

            $table->index('enrollment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
