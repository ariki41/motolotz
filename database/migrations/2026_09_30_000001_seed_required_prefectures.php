<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Insert the prefectures required by registration and address data.
     */
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $now = now();

        $prefectures = [
            ['name' => '北海道', 'name_kana' => 'ホッカイドウ'],
            ['name' => '青森県', 'name_kana' => 'アオモリケン'],
            ['name' => '岩手県', 'name_kana' => 'イワテケン'],
            ['name' => '宮城県', 'name_kana' => 'ミヤギケン'],
            ['name' => '秋田県', 'name_kana' => 'アキタケン'],
            ['name' => '山形県', 'name_kana' => 'ヤマガタケン'],
            ['name' => '福島県', 'name_kana' => 'フクシマケン'],
            ['name' => '茨城県', 'name_kana' => 'イバラキケン'],
            ['name' => '栃木県', 'name_kana' => 'トチギケン'],
            ['name' => '群馬県', 'name_kana' => 'グンマケン'],
            ['name' => '埼玉県', 'name_kana' => 'サイタマケン'],
            ['name' => '千葉県', 'name_kana' => 'チバケン'],
            ['name' => '東京都', 'name_kana' => 'トウキョウト'],
            ['name' => '神奈川県', 'name_kana' => 'カナガワケン'],
            ['name' => '新潟県', 'name_kana' => 'ニイガタケン'],
            ['name' => '富山県', 'name_kana' => 'トヤマケン'],
            ['name' => '石川県', 'name_kana' => 'イシカワケン'],
            ['name' => '福井県', 'name_kana' => 'フクイケン'],
            ['name' => '山梨県', 'name_kana' => 'ヤマナシケン'],
            ['name' => '長野県', 'name_kana' => 'ナガノケン'],
            ['name' => '岐阜県', 'name_kana' => 'ギフケン'],
            ['name' => '静岡県', 'name_kana' => 'シズオカケン'],
            ['name' => '愛知県', 'name_kana' => 'アイチケン'],
            ['name' => '三重県', 'name_kana' => 'ミエケン'],
            ['name' => '滋賀県', 'name_kana' => 'シガケン'],
            ['name' => '京都府', 'name_kana' => 'キョウトフ'],
            ['name' => '大阪府', 'name_kana' => 'オオサカフ'],
            ['name' => '兵庫県', 'name_kana' => 'ヒョウゴケン'],
            ['name' => '奈良県', 'name_kana' => 'ナラケン'],
            ['name' => '和歌山県', 'name_kana' => 'ワカヤマケン'],
            ['name' => '鳥取県', 'name_kana' => 'トットリケン'],
            ['name' => '島根県', 'name_kana' => 'シマネケン'],
            ['name' => '岡山県', 'name_kana' => 'オカヤマケン'],
            ['name' => '広島県', 'name_kana' => 'ヒロシマケン'],
            ['name' => '山口県', 'name_kana' => 'ヤマグチケン'],
            ['name' => '徳島県', 'name_kana' => 'トクシマケン'],
            ['name' => '香川県', 'name_kana' => 'カガワケン'],
            ['name' => '愛媛県', 'name_kana' => 'エヒメケン'],
            ['name' => '高知県', 'name_kana' => 'コウチケン'],
            ['name' => '福岡県', 'name_kana' => 'フクオカケン'],
            ['name' => '佐賀県', 'name_kana' => 'サガケン'],
            ['name' => '長崎県', 'name_kana' => 'ナガサキケン'],
            ['name' => '熊本県', 'name_kana' => 'クマモトケン'],
            ['name' => '大分県', 'name_kana' => 'オオイタケン'],
            ['name' => '宮崎県', 'name_kana' => 'ミヤザキケン'],
            ['name' => '鹿児島県', 'name_kana' => 'カゴシマケン'],
            ['name' => '沖縄県', 'name_kana' => 'オキナワケン'],
            ['name' => '海外', 'name_kana' => 'カイガイ'],
        ];

        $prefectures = array_map(
            fn (array $prefecture): array => [...$prefecture, 'created_at' => $now, 'updated_at' => $now],
            $prefectures,
        );

        DB::table('prefectures')->upsert(
            $prefectures,
            ['name'],
            ['name_kana', 'updated_at'],
        );
    }

    /**
     * Keep prefectures because application records may reference them.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};
