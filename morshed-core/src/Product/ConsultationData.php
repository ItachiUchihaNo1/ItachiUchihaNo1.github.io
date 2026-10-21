<?php
declare(strict_types=1);
namespace Morshed\Product;
use Morshed\Core\Database;

/** Additive booking metadata. Never run DDL inside a booking transaction. */
final class ConsultationData
{
    private bool $ready=false;
    public function __construct(private Database $db){}
    public function storage():void
    {
        if($this->ready||$this->db->featureReady('consultations14')){return;}
        try{$this->db->run('SELECT 1 FROM m_schedule_dates,m_booking_details,m_booking_reminders,m_schedule_recurrences LIMIT 0');}
        catch(\PDOException $e){
            if((int)($e->errorInfo[1]??0)!==1146||$this->db->pdo->inTransaction()){throw $e;}
            $this->db->run('CREATE TABLE IF NOT EXISTS m_schedule_dates (id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,local_date DATE NOT NULL,start_minute SMALLINT NOT NULL,end_minute SMALLINT NOT NULL,INDEX(mentor_id,local_date),FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id),CHECK(start_minute>=0 AND end_minute<=1440 AND end_minute>start_minute)) ENGINE=InnoDB');
            $this->db->run("CREATE TABLE IF NOT EXISTS m_booking_details (booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,subject VARCHAR(180) NOT NULL,experience_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,experience_title VARCHAR(500) NOT NULL DEFAULT '',requested_method VARCHAR(12) NOT NULL DEFAULT 'audio',amount BIGINT UNSIGNED NOT NULL DEFAULT 0,currency CHAR(3) NOT NULL DEFAULT 'IRR',payment_state VARCHAR(16) NOT NULL DEFAULT 'free',FOREIGN KEY(booking_id) REFERENCES m_bookings(id),FOREIGN KEY(experience_id) REFERENCES m_experiences(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $this->db->run('CREATE TABLE IF NOT EXISTS m_booking_reminders (booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,starts_at BIGINT UNSIGNED NOT NULL,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at BIGINT UNSIGNED NOT NULL,PRIMARY KEY(booking_id,starts_at,user_id),FOREIGN KEY(booking_id) REFERENCES m_bookings(id),FOREIGN KEY(user_id) REFERENCES m_users(id)) ENGINE=InnoDB');
            $this->db->run("CREATE TABLE IF NOT EXISTS m_schedule_recurrences (id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,kind VARCHAR(8) NOT NULL,weekday TINYINT NOT NULL DEFAULT 0,ordinal TINYINT NOT NULL DEFAULT 1,start_minute SMALLINT NOT NULL,end_minute SMALLINT NOT NULL,INDEX(mentor_id),FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id),CHECK(kind IN ('daily','monthly')),CHECK(weekday BETWEEN 0 AND 6),CHECK(ordinal BETWEEN 1 AND 5),CHECK(start_minute>=0 AND end_minute<=1440 AND end_minute>start_minute)) ENGINE=InnoDB");
        }$this->ready=true;$this->db->markFeatureReady('consultations14');
    }
}
