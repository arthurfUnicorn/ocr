<?php
/**
 * 解析器系統引導文件
 * 
 * 在使用解析器之前，請引入此文件
 */

// 加載 Traits
require_once __DIR__ . '/Parsers/Traits/SmartFieldMapping.php';
require_once __DIR__ . '/Parsers/Traits/TableExtraction.php';
require_once __DIR__ . '/Parsers/Traits/TextBlockParsing.php';

// 加載接口和基類
require_once __DIR__ . '/Parsers/ParserInterface.php';
require_once __DIR__ . '/Parsers/AbstractParser.php';

// 加載解析器
require_once __DIR__ . '/Parsers/DocParserJsonParser.php';
require_once __DIR__ . '/Parsers/GenericMarkdownParser.php';
require_once __DIR__ . '/Parsers/TextBlockParser.php';
require_once __DIR__ . '/Parsers/LlmAssistedParser.php';

// 加載驗證器
require_once __DIR__ . '/Validators/InvoiceDataValidator.php';

// 加載註冊表
require_once __DIR__ . '/ParserRegistry.php';
