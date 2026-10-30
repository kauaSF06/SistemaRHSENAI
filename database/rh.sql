-- RH Simples · esquema do banco (MySQL 8 / MariaDB 10.4+)
CREATE DATABASE IF NOT EXISTS rh_senai
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE rh_senai;

CREATE TABLE usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  email VARCHAR(180) NOT NULL UNIQUE,
  senha VARCHAR(255) NOT NULL,
  nivel ENUM('gerente','funcionario') NOT NULL DEFAULT 'funcionario',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE colaboradores (
  codigo INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL UNIQUE,
  nascimento DATE NOT NULL,
  telefone VARCHAR(25),
  cargo VARCHAR(100) NOT NULL,
  admissao DATE NOT NULL,
  salario DECIMAL(12,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

CREATE TABLE holerites (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  colaborador_codigo INT UNSIGNED NOT NULL,
  competencia DATE NOT NULL,
  salario_base DECIMAL(12,2) NOT NULL,
  proventos DECIMAL(12,2) NOT NULL DEFAULT 0,
  descontos DECIMAL(12,2) NOT NULL DEFAULT 0,
  liquido DECIMAL(12,2) NOT NULL,
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY competencia_colaborador (colaborador_codigo, competencia),
  FOREIGN KEY (colaborador_codigo) REFERENCES colaboradores(codigo)
);

CREATE TABLE rescisoes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  colaborador_codigo INT UNSIGNED NOT NULL,
  desligamento DATE NOT NULL,
  motivo VARCHAR(80) NOT NULL,
  saldo_salario DECIMAL(12,2) NOT NULL DEFAULT 0,
  decimo_terceiro DECIMAL(12,2) NOT NULL DEFAULT 0,
  ferias_proporcionais DECIMAL(12,2) NOT NULL DEFAULT 0,
  terco_ferias DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_estimado DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('rascunho','revisao','finalizada') NOT NULL DEFAULT 'rascunho',
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (colaborador_codigo) REFERENCES colaboradores(codigo)
);
