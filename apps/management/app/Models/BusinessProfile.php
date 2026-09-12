<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessProfile extends Model
{
    protected $table = 'business_profile';

    protected $fillable = [
        'business_name',
        'business_description',
        'services_offered',
        'service_areas',
        'business_hours',
        'contact_channels',
        'conversation_tone',
        'always_escalate_topics',
    ];

    /**
     * Singleton accessor. Older databases may contain the one profile under a
     * non-one primary key, so use the existing row rather than creating a
     * second profile merely because an auto-increment sequence has advanced.
     */
    public static function current(): self
    {
        $existing = self::query()->orderBy('id')->first();

        if ($existing !== null) {
            return $existing;
        }

        return self::create([
            'business_name' => 'ธุรกิจของฉัน',
            'business_description' => 'กรุณากรอกรายละเอียดธุรกิจในหน้าตั้งค่าโปรไฟล์',
        ]);
    }
}
