<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 学生证 —— 证件实体，与在读资格分别管理。
 * 一个学生历史上可持有多张证（补证链）；每个证号全局唯一，旧证号一旦
 * 挂失 / 被补证取代即进入终态，永不复用、永不恢复。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('card_no')->unique()->comment('证号，永久唯一，旧证号失效后不得再次发放');
            // active=有效  lost=已挂失（即时不可用）  replaced=已被新证取代（永久失效）
            $table->string('status', 16)->default('active');
            $table->string('scan_token', 64)->unique()->comment('二维码凭证，窗口扫码核验用');
            $table->unsignedSmallInteger('sequence')->default(1)->comment('第几版证，补证递增');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_cards');
    }
};
