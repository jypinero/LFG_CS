<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class UserDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'document_type',
        'custom_type',
        'document_name',
        'description',
        'reference_number',
        'file_path',
        'file_type',
        'file_size',
        'issued_by',
        'issue_date',
        'expiry_date',
        'verification_status',
        'verified_by',
        'verified_at',
        'verification_notes',
        // AI Processing Fields
        'ai_processed',
        'ai_confidence_score',
        'ai_extracted_data',
        'ai_validation_notes',
        'ai_flags',
        'ai_quality_score',
        'ai_name_matches',
        'ai_auto_verified',
        'ai_processed_at',
        'ai_ocr_text',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'verified_at' => 'datetime',
        // AI Fields
        'ai_processed' => 'boolean',
        'ai_auto_verified' => 'boolean',
        'ai_name_matches' => 'boolean',
        'ai_extracted_data' => 'array',
        'ai_flags' => 'array',
        'ai_processed_at' => 'datetime',
        'ai_confidence_score' => 'decimal:2',
        'ai_quality_score' => 'decimal:2',
    ];

    protected $appends = ['is_expired', 'file_url'];

    /**
     * User who owns this document
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * User who verified this document
     */
    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Check if document is expired
     */
    public function getIsExpiredAttribute()
    {
        if (!$this->expiry_date) {
            return false;
        }
        return Carbon::parse($this->expiry_date)->isPast();
    }

    /**
     * Get full file URL
     */
    public function getFileUrlAttribute()
    {
        if (!$this->file_path) {
            return null;
        }
        return url('storage/' . ltrim($this->file_path, '/'));
    }

    /**
     * Get display name for document type
     */
    public function getDocumentTypeDisplayAttribute()
    {
        if ($this->document_type === 'other' && $this->custom_type) {
            return $this->custom_type;
        }

        return match($this->document_type) {
            'government_id' => 'Government ID',
            'medical_certificate' => 'Medical Certificate',
            'waiver_form' => 'Waiver Form',
            'insurance_proof' => 'Insurance Proof',
            'parental_consent' => 'Parental Consent',
            default => ucfirst(str_replace('_', ' ', $this->document_type))
        };
    }

    /**
     * Scope for verified documents only
     */
    public function scopeVerified($query)
    {
        return $query->where('verification_status', 'verified');
    }

    /**
     * Scope for non-expired documents
     */
    public function scopeValid($query)
    {
        return $query->where(function($q) {
            $q->whereNull('expiry_date')
              ->orWhere('expiry_date', '>=', now());
        });
    }

    /**
     * Scope for specific document type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('document_type', $type);
    }
}

