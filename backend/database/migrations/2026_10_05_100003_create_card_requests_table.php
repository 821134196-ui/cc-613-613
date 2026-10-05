<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 业务申请单：挂失申请 / 补证申请，共用一张表（type 区分）。
 * status: pending=待受理 approved=已办结 rejected=已驳回。
 * 挂失即时生效也落单留痕，便于学生查询进度。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('card_id')->nullable()->constrained('student_cards')->nullOnDelete()
                ->comment('挂失申请针对的证件；补证申请关联其申请挂失的旧证');
            $table->foreignId('new_card_id')->nullable()->constrained('student_cards')->nullOnDelete()
                ->comment('补证办结后生成的新证');
            // report_loss=挂失申请  reissue=补证申请
            $table->string('type', 16);
            // pending=办理中  approved=已办结  rejected=已驳回
            $table->string('status', 16)->default('pending');
            $table->string('reason')->nullable()->comment('学生填写的原因');
            $table->string('contact_phone')->nullable()->comment('补证联系方式');
            $table->string('reject_reason')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_requests');
    }
};
