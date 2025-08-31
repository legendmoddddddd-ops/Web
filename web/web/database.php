<?php
require_once 'config.php';
require_once 'database_fallback.php';

class Database {
    private static $instance = null;
    private $client;
    private $database;
    private $useFallback = false;
    
    private function __construct() {
        try {
            // Check if MongoDB extension is available
            if (!class_exists('MongoDB\Client')) {
                throw new Exception('MongoDB extension not installed');
            }
            
            // MongoDB connection using MongoDB PHP Library
            $this->client = new MongoDB\Client(DatabaseConfig::MONGODB_URI);
            $this->database = $this->client->selectDatabase(DatabaseConfig::DATABASE_NAME);
            
            // Test connection
            $this->database->command(['ping' => 1]);
            
        } catch (Exception $e) {
            logError('MongoDB connection failed, using fallback: ' . $e->getMessage());
            $this->useFallback = true;
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function getFallback() {
        return DatabaseFallback::getInstance();
    }
    
    public function getCollection($collectionName) {
        if ($this->useFallback) {
            // Return null for fallback - methods will handle this
            return null;
        }
        return $this->database->selectCollection($collectionName);
    }
    
    // User operations
    public function createUser($telegramData) {
        if ($this->useFallback) {
            return $this->getFallback()->createUser($telegramData);
        }
        
        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        $userStats = $this->getCollection(DatabaseConfig::USER_STATS_COLLECTION);
        
        $userData = [
            'telegram_id' => $telegramData['id'],
            'username' => $telegramData['username'] ?? null,
            'display_name' => $telegramData['first_name'] . ' ' . ($telegramData['last_name'] ?? ''),
            'avatar_url' => $telegramData['photo_url'] ?? null,
            'role' => AppConfig::ROLE_FREE,
            'credits' => 50, // Starting credits
            'xcoin_balance' => 0,
            'status' => 'active',
            'last_login_at' => new MongoDB\BSON\UTCDateTime(),
            'membership_verified_at' => new MongoDB\BSON\UTCDateTime(),
            'created_at' => new MongoDB\BSON\UTCDateTime(),
            'updated_at' => new MongoDB\BSON\UTCDateTime()
        ];
        
        $statsData = [
            'user_id' => $telegramData['id'],
            'total_hits' => 0,
            'total_charge_cards' => 0,
            'total_live_cards' => 0,
            'expiry_date' => null,
            'updated_at' => new MongoDB\BSON\UTCDateTime()
        ];
        
        $users->insertOne($userData);
        $userStats->insertOne($statsData);
        
        return $userData;
    }
    
    public function getAllUsers($limit = 25, $offset = 0) {
        if ($this->useFallback) {
            $users = $this->getFallback()->loadData('users');
            return array_slice($users, $offset, $limit);
        }

        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        return $users->find([], ['limit' => $limit, 'skip' => $offset, 'sort' => ['created_at' => -1]])->toArray();
    }

    public function getUserById($userId) {
        if ($this->useFallback) {
            $users = $this->getFallback()->loadData('users');
            foreach ($users as $user) {
                // Check both _id and telegram_id fields for compatibility
                if ((isset($user['_id']) && $user['_id'] == $userId) || 
                    (isset($user['telegram_id']) && $user['telegram_id'] == $userId)) {
                    return $user;
                }
            }
            return null;
        }

        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        // Try to find by ObjectId first, then by telegram_id as fallback
        try {
            $result = $users->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
            if ($result) return $result;
        } catch (Exception $e) {
            // If ObjectId conversion fails, try as telegram_id
        }
        
        // Fallback to telegram_id search
        return $users->findOne(['telegram_id' => (int)$userId]);
    }
    
    public function getUserByTelegramId($telegramId) {
        if ($this->useFallback) {
            return $this->getFallback()->getUserByTelegramId($telegramId);
        }
        
        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        return $users->findOne(['telegram_id' => $telegramId]);
    }
    
    public function updateUserStatus($telegramId, $status) {
        if ($this->useFallback) {
            return $this->getFallback()->updateUserStatus($telegramId, $status);
        }

        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        $users->updateOne(
            ['telegram_id' => $telegramId],
            ['$set' => ['status' => $status, 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
        );
    }

    public function updateUserRole($telegramId, $role) {
        if ($this->useFallback) {
            return $this->getFallback()->updateUserRole($telegramId, $role);
        }

        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        $result = $users->updateOne(
            ['telegram_id' => $telegramId],
            ['$set' => ['role' => $role, 'updated_at' => new MongoDB\BSON\UTCDateTime()]]
        );
        return $result->getModifiedCount() > 0;
    }

    public function updateUserLastLogin($telegramId) {
        if ($this->useFallback) {
            return $this->getFallback()->updateUserLastLogin($telegramId);
        }
        
        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        $users->updateOne(
            ['telegram_id' => $telegramId],
            ['$set' => [
                'last_login_at' => new MongoDB\BSON\UTCDateTime(),
                'updated_at' => new MongoDB\BSON\UTCDateTime()
            ]]
        );
    }
    
    public function getUserStats($telegramId) {
        if ($this->useFallback) {
            return $this->getFallback()->getUserStats($telegramId);
        }
        
        $userStats = $this->getCollection(DatabaseConfig::USER_STATS_COLLECTION);
        return $userStats->findOne(['user_id' => $telegramId]);
    }
    
    public function updateUserStats($telegramId, $statsUpdate) {
        $userStats = $this->getCollection(DatabaseConfig::USER_STATS_COLLECTION);
        $statsUpdate['updated_at'] = new MongoDB\BSON\UTCDateTime();
        $userStats->updateOne(
            ['user_id' => $telegramId],
            ['$set' => $statsUpdate]
        );
    }
    
    public function deductCredits($telegramId, $amount) {
        if ($this->useFallback) {
            return $this->getFallback()->deductCredits($telegramId, $amount);
        }
        
        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        return $users->updateOne(
            ['telegram_id' => $telegramId, 'credits' => ['$gte' => $amount]],
            ['$inc' => ['credits' => -$amount], '$set' => ['updated_at' => new MongoDB\BSON\UTCDateTime()]]
        )->getModifiedCount() > 0;
    }
    
    public function addCredits($telegramId, $amount) {
        if ($this->useFallback) {
            $users = $this->getFallback()->loadData('users');
            foreach ($users as &$user) {
                if ($user['telegram_id'] == $telegramId) {
                    $user['credits'] += $amount;
                    $user['updated_at'] = time();
                    break;
                }
            }
            $this->getFallback()->saveData('users', $users);
            return;
        }
        
        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        $users->updateOne(
            ['telegram_id' => $telegramId],
            ['$inc' => ['credits' => $amount], '$set' => ['updated_at' => new MongoDB\BSON\UTCDateTime()]]
        );
    }
    
    // Daily credit claim
    public function canClaimDailyCredits($telegramId) {
        if ($this->useFallback) {
            return $this->getFallback()->canClaimDailyCredits($telegramId);
        }
        
        $claims = $this->getCollection(DatabaseConfig::DAILY_CREDIT_CLAIMS_COLLECTION);
        $today = date('Y-m-d');
        $existingClaim = $claims->findOne([
            'user_id' => $telegramId,
            'claim_date' => $today
        ]);
        return $existingClaim === null;
    }
    
    // Alias for backward compatibility
    public function canClaimDailyCredit($telegramId) {
        return $this->canClaimDailyCredits($telegramId);
    }
    
    public function claimDailyCredits($telegramId) {
        if ($this->useFallback) {
            return $this->getFallback()->claimDailyCredits($telegramId);
        }
        
        if (!$this->canClaimDailyCredits($telegramId)) {
            return false;
        }
        
        $claims = $this->getCollection(DatabaseConfig::DAILY_CREDIT_CLAIMS_COLLECTION);
        $claims->insertOne([
            'user_id' => $telegramId,
            'claim_date' => date('Y-m-d'),
            'created_at' => new MongoDB\BSON\UTCDateTime()
        ]);
        
        $this->addCredits($telegramId, AppConfig::DAILY_CREDIT_AMOUNT);
        return true;
    }
    
    // Alias for backward compatibility
    public function claimDailyCredit($telegramId) {
        return $this->claimDailyCredits($telegramId);
    }
    
    // Presence system
    public function updatePresence($telegramId) {
        if ($this->useFallback) {
            return $this->getFallback()->updatePresence($telegramId);
        }
        
        $presence = $this->getCollection(DatabaseConfig::PRESENCE_HEARTBEATS_COLLECTION);
        $presence->updateOne(
            ['user_id' => $telegramId],
            ['$set' => ['last_seen_at' => new MongoDB\BSON\UTCDateTime()]],
            ['upsert' => true]
        );
    }
    
    public function getOnlineUsers($limit = 50) {
        if ($this->useFallback) {
            return $this->getFallback()->getOnlineUsers($limit);
        }
        
        $presence = $this->getCollection(DatabaseConfig::PRESENCE_HEARTBEATS_COLLECTION);
        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        
        $fiveMinutesAgo = new MongoDB\BSON\UTCDateTime((time() - 300) * 1000);
        
        $pipeline = [
            ['$match' => ['last_seen_at' => ['$gte' => $fiveMinutesAgo]]],
            ['$lookup' => [
                'from' => DatabaseConfig::USERS_COLLECTION,
                'localField' => 'user_id',
                'foreignField' => 'telegram_id',
                'as' => 'user'
            ]],
            ['$unwind' => '$user'],
            ['$limit' => $limit],
            ['$sort' => ['last_seen_at' => -1]]
        ];
        
        return $presence->aggregate($pipeline)->toArray();
    }
    
    // Leaderboard
    public function getTopUsers($limit = 10) {
        if ($this->useFallback) {
            return $this->getFallback()->getTopUsers($limit);
        }
        
        $userStats = $this->getCollection(DatabaseConfig::USER_STATS_COLLECTION);
        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        
        $pipeline = [
            ['$lookup' => [
                'from' => DatabaseConfig::USERS_COLLECTION,
                'localField' => 'user_id',
                'foreignField' => 'telegram_id',
                'as' => 'user'
            ]],
            ['$unwind' => '$user'],
            ['$sort' => ['total_hits' => -1]],
            ['$limit' => $limit]
        ];
        
        return $userStats->aggregate($pipeline)->toArray();
    }
    
    // Global statistics
    public function getGlobalStats() {
        if ($this->useFallback) {
            return $this->getFallback()->getGlobalStats();
        }
        
        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        $userStats = $this->getCollection(DatabaseConfig::USER_STATS_COLLECTION);
        
        $totalUsers = $users->countDocuments();
        
        $statsAgg = $userStats->aggregate([
            ['$group' => [
                '_id' => null,
                'total_hits' => ['$sum' => '$total_hits'],
                'total_charge_cards' => ['$sum' => '$total_charge_cards'],
                'total_live_cards' => ['$sum' => '$total_live_cards']
            ]]
        ])->toArray();
        
        $stats = $statsAgg[0] ?? [
            'total_hits' => 0,
            'total_charge_cards' => 0,
            'total_live_cards' => 0
        ];
        
        return [
            'total_users' => $totalUsers,
            'total_hits' => $stats['total_hits'],
            'total_charge_cards' => $stats['total_charge_cards'],
            'total_live_cards' => $stats['total_live_cards']
        ];
    }
    
    // Admin Dashboard Stats
    public function getTotalUsersCount() {
        if ($this->useFallback) {
            $users = $this->getFallback()->loadData('users');
            return count($users);
        }
        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        return $users->countDocuments();
    }

    public function getTotalCreditsClaimed() {
        if ($this->useFallback) {
            $claims = $this->getFallback()->loadData('daily_credit_claims');
            return count($claims);
        }
        $claims = $this->getCollection(DatabaseConfig::DAILY_CREDIT_CLAIMS_COLLECTION);
        return $claims->countDocuments();
    }

    public function getTotalToolUses() {
        if ($this->useFallback) {
            // Fallback does not currently log tool usage, returning 0.
            // To implement, create a 'tool_usage.json' and log to it.
            return 0;
        }
        $toolUsage = $this->getCollection(DatabaseConfig::TOOL_USAGE_COLLECTION);
        return $toolUsage->countDocuments();
    }

    public function logAuditAction($adminId, $action, $targetId = null, $details = []) {
        if ($this->useFallback) {
            return $this->getFallback()->logAuditAction($adminId, $action, $targetId, $details);
        }

        $auditLog = $this->getCollection('audit_logs');
        $auditLog->insertOne([
            'admin_id' => $adminId,
            'action' => $action,
            'target_id' => $targetId,
            'details' => $details,
            'timestamp' => new MongoDB\BSON\UTCDateTime(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }

    public function getAuditLogs($limit = 50, $offset = 0) {
        if ($this->useFallback) {
            return $this->getFallback()->getAuditLogs($limit, $offset);
        }

        $auditLog = $this->getCollection('audit_logs');
        return $auditLog->find([], [
            'limit' => $limit, 
            'skip' => $offset, 
            'sort' => ['timestamp' => -1]
        ])->toArray();
    }

    // Tool usage logging
    public function logToolUsage($userId, $toolName, $count, $creditsUsed) {
        if ($this->useFallback) {
            return $this->getFallback()->logToolUsage($userId, $toolName, $count, $creditsUsed);
        }
        
        $toolUsage = $this->getCollection(DatabaseConfig::TOOL_USAGE_COLLECTION);
        
        $usageData = [
            'user_id' => $userId,
            'tool_name' => $toolName,
            'usage_count' => $count,
            'credits_used' => $creditsUsed,
            'created_at' => new MongoDB\BSON\UTCDateTime()
        ];
        
        return $toolUsage->insertOne($usageData);
    }
    
    public function redeemCredits($userId, $credits, $xcoinCost) {
        if ($this->useFallback) {
            return $this->getFallback()->redeemCredits($userId, $credits, $xcoinCost);
        }
        
        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        
        return $users->updateOne(
            ['telegram_id' => $userId],
            [
                '$inc' => [
                    'credits' => $credits,
                    'xcoin_balance' => -$xcoinCost
                ],
                '$set' => ['updated_at' => new MongoDB\BSON\UTCDateTime()]
            ]
        );
    }
    
    public function upgradeMembership($userId, $plan, $xcoinCost) {
        if ($this->useFallback) {
            return $this->getFallback()->upgradeMembership($userId, $plan, $xcoinCost);
        }
        
        $users = $this->getCollection(DatabaseConfig::USERS_COLLECTION);
        
        // Set daily credit amounts based on plan
        $dailyCredits = [
            'premium' => 25,
            'vip' => 50
        ];
        
        return $users->updateOne(
            ['telegram_id' => $userId],
            [
                '$set' => [
                    'role' => $plan,
                    'updated_at' => new MongoDB\BSON\UTCDateTime()
                ],
                '$inc' => [
                    'xcoin_balance' => -$xcoinCost,
                    'credits' => $dailyCredits[$plan] ?? 0
                ]
            ]
        );
    }
}
?>
