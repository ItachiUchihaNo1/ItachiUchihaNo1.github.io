<?php
declare(strict_types=1);
namespace Morshed\Core;
final class Audit
{
    public function __construct(private Database $db) {}
    // Deliberately no free-form context/raw request fields that could log passwords or OTPs.
    public function add(?string $actor, string $realm, string $event, ?string $subject = null): void
    { $this->db->run('INSERT INTO m_audit_logs(actor_user_id,realm,event,subject_id,created_at) VALUES (?,?,?,?,?)',[$actor,$realm,$event,$subject,time()]); }
    public function notify(string $user, string $title, string $body,?string $destination=null,string $category='account'): void
    {if(!in_array($category,['account','sessions','system'],true)||($destination!==null&&!preg_match('~^/(?:account|notifications|mentor|mentor/experiences|sessions|booking\?id=[a-f0-9]{32}|ticket\?id=[a-f0-9]{32})$~D',$destination))){throw new \LogicException('Invalid notification destination.');}
        $this->db->run('INSERT INTO m_notifications(id,user_id,title,body,created_at,destination,category) VALUES (?,?,?,?,?,?,?)',[Security::id(),$user,$title,$body,time(),$destination,$category]); }
}
