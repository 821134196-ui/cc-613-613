<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 学生 —— 优惠“资格”实体。
 * enrollment_status 表示在读资格（在读 / 休学），与证件实体分离管理：
 * 休学、恢复只改变资格，从不改变任何学生证的状态。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_number')->unique()->comment('完整学号，仅学生本人与后台可见，窗口端永不返回');
            $table->string('name');
            $table->string('college')->nullable()->comment('学院');
            $table->string('password');
            $table->string('enrollment_status', 16)->default('enrolled')->comment('enrolled=在读 / suspended=休学');
            $table->timestamp('suspended_at')->nullable();
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
