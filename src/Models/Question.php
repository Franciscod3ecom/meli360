<?php
namespace App\Models;

use App\Core\Database;
use PDO;

class Question
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Cria uma nova pergunta no banco de dados ou a atualiza, se já existir.
     *
     * @param array $data Os dados da pergunta vindos da notificação do ML.
     * @return int|false O ID da pergunta inserida ou atualizada, ou false em caso de erro.
     */
    public function createOrUpdate(array $data): int|false
    {
        $sql = "INSERT INTO questions (ml_question_id, ml_item_id, saas_user_id, ml_user_id, question_text, question_date, question_status)
                VALUES (:ml_question_id, :ml_item_id, :saas_user_id, :ml_user_id, :question_text, :question_date, 'UNANSWERED')
                ON DUPLICATE KEY UPDATE
                    question_text = VALUES(question_text),
                    question_status = IF(answer_text IS NULL, 'UNANSWERED', 'ANSWERED'),
                    updated_at = NOW()";
        
        $stmt = $this->db->prepare($sql);

        $success = $stmt->execute([
            ':ml_question_id' => $data['ml_question_id'],
            ':ml_item_id' => $data['ml_item_id'],
            ':saas_user_id' => $data['saas_user_id'],
            ':ml_user_id' => $data['ml_user_id'],
            ':question_text' => $data['question_text'],
            ':question_date' => $data['question_date']
        ]);

        if ($success) {
            $lastInsertId = $this->db->lastInsertId();
            return (int)($lastInsertId > 0 ? $lastInsertId : $this->findIdByMlQuestionId($data['ml_question_id']));
        }
        return false;
    }

    /**
     * Atualiza o status e a resposta de uma pergunta.
     *
     * @param int $ml_question_id O ID da pergunta no Mercado Livre.
     * @param string $status O novo status.
     * @param string|null $generatedAnswer A resposta gerada pela IA.
     * @param string|null $sentAnswer A resposta que foi efetivamente enviada.
     * @return bool
     */
    public function updateAnswer(int $ml_question_id, string $status, ?string $generatedAnswer, ?string $sentAnswer): bool
    {
        $sql = "UPDATE questions SET 
                    question_status = :status,
                    generated_answer = :generated_answer,
                    answer_text = :answer_text,
                    updated_at = NOW()
                WHERE ml_question_id = :ml_question_id";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':status' => $status,
            ':generated_answer' => $generatedAnswer,
            ':answer_text' => $sentAnswer,
            ':ml_question_id' => $ml_question_id
        ]);
    }

    /**
     * Busca todas as perguntas de um usuário da plataforma.
     *
     * @param int $saasUserId O ID do usuário na nossa plataforma.
     * @return array
     */
    public function findAllBySaasUserId(int $saasUserId): array
    {
        $sql = "SELECT q.*, a.title as anuncio_title, a.permalink 
                FROM questions q
                LEFT JOIN anuncios a ON q.ml_item_id = a.ml_item_id
                WHERE q.saas_user_id = :saas_user_id 
                ORDER BY q.question_date DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':saas_user_id' => $saasUserId]);
        return $stmt->fetchAll();
    }

    /**
     * Busca o ID interno da pergunta a partir do ID do Mercado Livre.
     */
    private function findIdByMlQuestionId(int $ml_question_id): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM questions WHERE ml_question_id = :ml_question_id");
        $stmt->execute([':ml_question_id' => $ml_question_id]);
        $result = $stmt->fetchColumn();
        return $result ? (int)$result : null;
    }
}