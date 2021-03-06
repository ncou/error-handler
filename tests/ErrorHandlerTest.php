<?php

namespace Jasny;

use Jasny\ErrorHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use PHPUnit_Framework_MockObject_MockObject as MockObject;
use PHPUnit_Framework_MockObject_Matcher_InvokedCount as InvokedCount;
use Jasny\TestHelper;

/**
 * @covers Jasny\ErrorHandler
 * @covers Jasny\ErrorHandler\Logging
 * @covers Jasny\ErrorHandler\ErrorCodes
 * @covers Jasny\ErrorHandler\HandleUncaughtError
 * @covers Jasny\ErrorHandler\HandleShutdownError
 * @covers Jasny\ErrorHandler\HandleUncaughtException
 * @covers Jasny\ErrorHandler\FunctionWrapper
 */
class ErrorHandlerTest extends \PHPUnit_Framework_TestCase
{
    use TestHelper;
    
    /**
     * @var ErrorHandler|MockObject
     */
    protected $errorHandler;
    
    public function setUp()
    {
        $this->errorHandler = $this->getMockBuilder(ErrorHandler::class)
            ->setMethods(['errorReporting', 'errorGetLast', 'setErrorHandler', 'setExceptionHandler',
                'registerShutdownFunction', 'clearOutputBuffer'])
            ->getMock();
    }
    
    
    public function testSetError()
    {
        $exception = new \Exception();
        
        $this->errorHandler->setError($exception);
        $this->assertSame($exception, $this->errorHandler->getError());
    }

    public function testAsMiddleware()
    {
        $middleware = $this->errorHandler->asMiddleware();
        
        $this->assertInstanceOf(ErrorHandler\Middleware::class, $middleware);
        $this->assertAttributeSame($this->errorHandler, 'errorHandler', $middleware);
    }
    
    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     */
    public function testSetErrorWithInvalid()
    {
        @$this->errorHandler->setError('foo');
        $this->assertNull($this->errorHandler->getError());
        
        $this->errorHandler->setError('foo');
    }

    
    public function testSetLogger()
    {
        $logger = $this->createMock(LoggerInterface::class);
        
        $errorHandler = $this->errorHandler;
        $errorHandler->setLogger($logger);
        
        $this->assertSame($logger, $errorHandler->getLogger());
    }
    
    public function errorProvider()
    {
        return [
            [E_ERROR, LogLevel::ERROR, 'Fatal error'],
            [E_USER_ERROR, LogLevel::ERROR, 'Fatal error'],
            [E_RECOVERABLE_ERROR, LogLevel::ERROR, 'Fatal error'],
            [E_WARNING, LogLevel::WARNING, 'Warning'],
            [E_USER_WARNING, LogLevel::WARNING, 'Warning'],
            [E_PARSE, LogLevel::CRITICAL, 'Parse error'],
            [E_NOTICE, LogLevel::NOTICE, 'Notice'],
            [E_USER_NOTICE, LogLevel::NOTICE, 'Notice'],
            [E_CORE_ERROR, LogLevel::CRITICAL, 'Core error'],
            [E_CORE_WARNING, LogLevel::WARNING, 'Core warning'],
            [E_COMPILE_ERROR, LogLevel::CRITICAL, 'Compile error'],
            [E_COMPILE_WARNING, LogLevel::WARNING, 'Compile warning'],
            [E_STRICT, LogLevel::INFO, 'Strict standards'],
            [E_DEPRECATED, LogLevel::INFO, 'Deprecated'],
            [E_USER_DEPRECATED, LogLevel::INFO, 'Deprecated'],
            [99999999, LogLevel::ERROR, 'Unknown error']
        ];
    }
    
    /**
     * @dataProvider errorProvider
     * 
     * @param int    $code
     * @param string $level
     * @param string $type
     */
    public function testLogError($code, $level, $type)
    {
        $error = new \ErrorException("no good", 0, $code, "foo.php", 42);
        $context = ['error' => $error, 'code' => $code, 'message' => "no good", 'file' => 'foo.php', 'line' => 42];
        
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')
            ->with($level, "$type: no good at foo.php line 42", $context);
        
        $errorHandler = $this->errorHandler;
        $errorHandler->setLogger($logger);

        $errorHandler->log($error);
    }
    
    public function testLogException()
    {
        $exception = $this->createMock(\Exception::class);
        
        $message = $this->stringStartsWith('Uncaught Exception ' . get_class($exception));
        $context = ['exception' => $exception];
        
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')
            ->with(LogLevel::ERROR, $message, $context);
        
        $errorHandler = $this->errorHandler;
        $errorHandler->setLogger($logger);

        $errorHandler->log($exception);
    }
    
    public function testLogString()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')->with(LogLevel::WARNING, "Unable to log a string");
        
        $errorHandler = $this->errorHandler;
        $errorHandler->setLogger($logger);

        $errorHandler->log('foo');
    }
    
    public function testLogObject()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')->with(LogLevel::WARNING, "Unable to log a stdClass object");
        
        $errorHandler = $this->errorHandler;
        $errorHandler->setLogger($logger);

        $errorHandler->log(new \stdClass());
    }
    
    
    public function testConverErrorsToExceptions()
    {
        $errorHandler = $this->errorHandler;

        $errorHandler->expects($this->once())->method('setErrorHandler')
            ->with([$errorHandler, 'handleError'])
            ->willReturn(null);
        
        $errorHandler->converErrorsToExceptions();
        
        $this->assertSame(0, $errorHandler->getLoggedErrorTypes());
    }
    
    
    public function logUncaughtErrorProvider()
    {
        return [
            [E_ALL, $this->once(), $this->once()],
            [E_WARNING | E_USER_WARNING, $this->once(), $this->never()],
            [E_NOTICE | E_USER_NOTICE, $this->once(), $this->never()],
            [E_STRICT, $this->once(), $this->never()],
            [E_DEPRECATED | E_USER_DEPRECATED, $this->once(), $this->never()],
            [E_PARSE, $this->never(), $this->once()],
            [E_ERROR, $this->never(), $this->once()],
            [E_ERROR | E_USER_ERROR, $this->once(), $this->once()],
            [E_RECOVERABLE_ERROR | E_USER_ERROR, $this->once(), $this->never()]
        ];
    }
    
    /**
     * @dataProvider logUncaughtErrorProvider
     * 
     * @param int          $code
     * @param InvokedCount $expectErrorHandler
     * @param InvokedCount $expectShutdownFunction
     */
    public function testLogUncaughtError($code, InvokedCount $expectErrorHandler, InvokedCount $expectShutdownFunction)
    {
        $errorHandler = $this->errorHandler;
        
        $errorHandler->expects($expectErrorHandler)->method('setErrorHandler')
            ->with([$errorHandler, 'handleError'])
            ->willReturn(null);
        
        $errorHandler->expects($expectShutdownFunction)->method('registerShutdownFunction')
            ->with([$errorHandler, 'shutdownFunction']);

        $errorHandler->expects($this->never())->method('setExceptionHandler');
        
        $errorHandler->logUncaught($code);
        
        $this->assertSame($code, $errorHandler->getLoggedErrorTypes());
    }
    
    public function testLogUncaughtException()
    {
        $errorHandler = $this->errorHandler;
        
        $errorHandler->expects($this->never())->method('setErrorHandler');
        $errorHandler->expects($this->never())->method('registerShutdownFunction');
        
        $errorHandler->expects($this->once())->method('setExceptionHandler')
            ->with([$errorHandler, 'handleException'])
            ->willReturn(null);
        
        $errorHandler->logUncaught(\Exception::class);
        
        $this->assertSame([\Exception::class], $errorHandler->getLoggedExceptionClasses());
    }
    
    public function testLogUncaughtCombine()
    {
        $errorHandler = $this->errorHandler;
        
        $errorHandler->logUncaught(E_NOTICE | E_USER_NOTICE);
        $errorHandler->logUncaught(E_WARNING | E_USER_WARNING);
        $errorHandler->logUncaught(E_ERROR);
        $errorHandler->logUncaught(E_ERROR | E_PARSE);
        
        $errorHandler->logUncaught(\LogicException::class);
        $errorHandler->logUncaught(\UnderflowException::class);
        
        $this->assertSame(
            E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING | E_ERROR | E_PARSE,
            $errorHandler->getLoggedErrorTypes()
        );
        
        $this->assertSame(
            [\LogicException::class, \UnderflowException::class],
            $errorHandler->getLoggedExceptionClasses()
        );
    }
    
    /**
     * @expectedException InvalidArgumentException
     */
    public function testLogUncaughtInvalid()
    {
        $this->errorHandler->logUncaught([]);
    }
    

    public function testInitErrorHandler()
    {
        $errorHandler = $this->errorHandler;
        
        $callback = function() {};
        
        $errorHandler->expects($this->once())->method('setErrorHandler')
            ->with([$errorHandler, 'handleError'])
            ->willReturn($callback);
        
        $errorHandler->logUncaught(E_WARNING);
        
        // Subsequent calls should have no effect
        $errorHandler->logUncaught(E_WARNING);
        
        $this->assertSame($callback, $errorHandler->getChainedErrorHandler());
    }
    
    public function testInitExceptionHandler()
    {
        $errorHandler = $this->errorHandler;

        $callback = function() {};
        
        $errorHandler->expects($this->once())->method('setExceptionHandler')
            ->with([$errorHandler, 'handleException'])
            ->willReturn($callback);
        
        $errorHandler->logUncaught(\Exception::class);
        
        // Subsequent calls should have no effect
        $errorHandler->logUncaught(\Exception::class);
        
        $this->assertSame($callback, $errorHandler->getChainedExceptionHandler());
    }
    
    public function testInitShutdownFunction()
    {
        $errorHandler = $this->errorHandler;

        $errorHandler->expects($this->once())->method('registerShutdownFunction')
            ->with([$errorHandler, 'shutdownFunction']);
        
        $errorHandler->logUncaught(E_PARSE);
        
        // Subsequent calls should have no effect
        $errorHandler->logUncaught(E_PARSE);
        
        $this->assertAttributeNotEmpty('reservedMemory', $errorHandler);
    }
    

    public function handleErrorProvider()
    {
        return [
            [0, E_WARNING, $this->never(), false],
            
            [E_ALL, E_RECOVERABLE_ERROR, $this->once(), true],
            [E_ALL, E_WARNING, $this->once(), false],
            [E_ALL, E_NOTICE, $this->once(), false],
            
            [E_WARNING | E_USER_WARNING, E_RECOVERABLE_ERROR, $this->never(), true],
            [E_WARNING | E_USER_WARNING, E_WARNING, $this->once(), false],
            [E_WARNING | E_USER_WARNING, E_NOTICE, $this->never(), false],
            
            [E_STRICT, E_RECOVERABLE_ERROR, $this->never(), true],
            [E_STRICT, E_STRICT, $this->once(), false],
            
            [E_RECOVERABLE_ERROR | E_USER_ERROR, E_RECOVERABLE_ERROR, $this->once(), true],
            [E_RECOVERABLE_ERROR | E_USER_ERROR, E_WARNING, $this->never(), false],
            [E_RECOVERABLE_ERROR | E_USER_ERROR, E_NOTICE, $this->never(), false],
            [E_RECOVERABLE_ERROR | E_USER_ERROR, E_STRICT, $this->never(), false]
        ];
    }
    
    /**
     * @dataProvider handleErrorProvider
     * 
     * @param int          $logUncaught
     * @param int          $code
     * @param InvokedCount $expectLog
     */
    public function testHandleErrorWithLogging($logUncaught, $code, InvokedCount $expectLog)
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($expectLog)->method('log')
            ->with($this->isType('string'), $this->stringEndsWith("no good at foo.php line 42"), $this->anything());
        
        $errorHandler = $this->errorHandler;
        $errorHandler->expects($this->once())->method('errorReporting')->willReturn(E_ALL | E_STRICT);
        
        $errorHandler->setLogger($logger);
        $errorHandler->logUncaught($logUncaught);
        
        $this->errorHandler->handleError($code, 'no good', 'foo.php', 42, []);
    }
    
    /**
     * @dataProvider handleErrorProvider
     * 
     * @param int          $logUncaught          Ignored
     * @param int          $code
     * @param InvokedCount $expectLog       Ignored
     * @param boolean      $expectException
     */
    public function testHandleErrorWithConvertError($logUncaught, $code, InvokedCount $expectLog, $expectException)
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('log');
        
        $errorHandler = $this->errorHandler;
        $errorHandler->expects($this->once())->method('errorReporting')->willReturn(E_ALL | E_STRICT);
        
        $errorHandler->setLogger($logger);
        
        $errorHandler->converErrorsToExceptions();
        
        try {
            $this->errorHandler->handleError($code, 'no good', 'foo.php', 42, []);
            
            if ($expectException) {
                $this->fail("Expected error exception wasn't thrown");
            }
        } catch (\ErrorException $exception) {
            if (!$expectException) {
                $this->fail("Error exception shouldn't have been thrown");
            }
            
            $this->assertInstanceOf(\ErrorException::class, $exception);
            $this->assertEquals('no good', $exception->getMessage());
            $this->assertEquals('foo.php', $exception->getFile());
            $this->assertEquals(42, $exception->getLine());
        }
    }
    
    public function testHandleErrorChaining()
    {
        $handler = $this->createCallbackMock($this->once(), [E_USER_WARNING, 'no good', 'foo.php', 42, []], false);

        $errorHandler = $this->errorHandler;
        $errorHandler->expects($this->once())->method('errorReporting')->willReturn(E_ALL | E_STRICT);
        $errorHandler->expects($this->once())->method('setErrorHandler')->willReturn($handler);
        
        $errorHandler->logUncaught(E_USER_WARNING);
        
        $ret = $errorHandler->handleError(E_USER_WARNING, 'no good', 'foo.php', 42, []);
        
        $this->assertSame($ret, false);
    }
    
    
    public function handleExceptionProvider()
    {
        return [
            [[\Exception::class], new \Exception('no good'), $this->once()],
            [[\Exception::class], new \LogicException('no good'), $this->once()],
            [[\LogicException::class, \RuntimeException::class], new \Exception('no good'), $this->never()],
            [[], new \Exception('no good'), $this->never()],
            [[E_USER_ERROR], new \ErrorException('no good', null, E_USER_ERROR), $this->once()],
            [[E_ERROR], new \ErrorException('no good', null, E_USER_ERROR), $this->never()],
        ];
    }
    
    /**
     * @dataProvider handleExceptionProvider
     * 
     * @param array        $logUncaught
     * @param \Exception   $exception
     * @param InvokedCount $expectLog
     */
    public function testHandleException(array $logUncaught, \Exception $exception, InvokedCount $expectLog)
    {
        $class = get_class($exception);
        
        if ($exception instanceof \ErrorException) {
            $message = 'Fatal error: no good';
        } else {
            $message = sprintf('Uncaught Exception %s: "%s"', $class, "no good");
        }
        
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($expectLog)->method('log')
            ->with($this->isType('string'), $this->stringStartsWith($message), $this->anything());

        $errorHandler = $this->errorHandler;
        
        $errorHandler->setLogger($logger);
        
        foreach ($logUncaught as $exceptionClass) {
            $errorHandler->logUncaught($exceptionClass);
        }

        $this->expectException($class);
        $this->expectExceptionMessage('no good');
        
        $errorHandler->handleException($exception);
    }
    
    public function testHandleExceptionChaining()
    {
        $exception = new \Exception('no good');
        $handler = $this->createCallbackMock($this->once(), [$exception]);

        $errorHandler = $this->errorHandler;
        $errorHandler->expects($this->exactly(2))->method('setExceptionHandler')
            ->withConsecutive([[$errorHandler, 'handleException']], [null])
            ->willReturnOnConsecutiveCalls($handler, [$errorHandler, 'handleException']);
        
        $errorHandler->expects($this->once())->method('setErrorHandler')->with(null);
        
        $errorHandler->logUncaught(\Exception::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('no good');
        
        $errorHandler->handleException($exception);
    }
    
    
    public function shutdownFunctionProvider()
    {
        return [
            [E_ALL, E_PARSE, $this->once()],
            [E_ERROR | E_WARNING, E_PARSE, $this->never()],
            [E_ALL, E_ERROR, $this->once()],
            [E_ALL, E_USER_ERROR, $this->never()],
            [E_ALL, E_WARNING, $this->never()],
            [E_ALL, null, $this->never()]
        ];
    }
    
    /**
     * @dataProvider shutdownFunctionProvider
     * 
     * @param int          $logUncaught         Ignored
     * @param int          $code
     * @param InvokedCount $expectLog       Ignored
     */
    public function testShutdownFunction($logUncaught, $code, InvokedCount $expectLog)
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($expectLog)->method('log')
            ->with($this->isType('string'), $this->stringEndsWith("no good at foo.php line 42"), $this->anything());
        
        $errorHandler = $this->errorHandler;
        
        $error = [
            'type' => $code,
            'message' => 'no good',
            'file' => 'foo.php',
            'line' => 42
        ];
        
        $errorHandler->expects($this->once())->method('errorGetLast')
            ->willReturn($code ? $error : null);
        
        $errorHandler->setLogger($logger);
        $errorHandler->logUncaught($logUncaught);
        
        $this->assertAttributeNotEmpty('reservedMemory', $errorHandler);
        
        $errorHandler->shutdownFunction();
        
        $this->assertAttributeEmpty('reservedMemory', $errorHandler);
    }
    
    
    public function onFatalErrorProvider()
    {
        return [
            [true, $this->once()],
            [false, $this->never()]
        ];
    }
    
    /**
     * @dataProvider onFatalErrorProvider
     * 
     * @param boolean      $clearOutput
     * @param InvokedCount $expectClear
     */
    public function testOnFatalErrorFromHandleException($clearOutput, InvokedCount $expectClear)
    {
        $exception = new \Exception('no good');
        
        $handler = $this->getMockBuilder(\stdClass::class)->setMethods(['__invoke'])->getMock();
        $handler->expects($this->once())->method('__invoke')
            ->with($exception);

        $errorHandler = $this->errorHandler;
        
        $errorHandler->expects($this->exactly(2))->method('setExceptionHandler')
            ->withConsecutive([[$errorHandler, 'handleException']], [null])
            ->willReturnOnConsecutiveCalls($handler, [$errorHandler, 'handleException']);
        
        $errorHandler->expects($expectClear)->method('clearOutputBuffer');
        
        $callback = $this->getMockBuilder(\stdClass::class)->setMethods(['__invoke'])->getMock();
        $callback->expects($this->once())->method('__invoke')
            ->with($exception);
        
        $errorHandler->onFatalError($callback, $clearOutput);
        
        $errorHandler->logUncaught(\Exception::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('no good');
        
        $errorHandler->handleException($exception);
    }
    
    /**
     * @dataProvider onFatalErrorProvider
     * 
     * @param boolean      $clearOutput
     * @param InvokedCount $expectClear
     */
    public function testOnFatalErrorFromShutdownFunction($clearOutput, InvokedCount $expectClear)
    {
        $errorHandler = $this->errorHandler;
        
        $error = [
            'type' => E_ERROR,
            'message' => 'no good',
            'file' => 'foo.php',
            'line' => 42
        ];
        
        $errorHandler->expects($this->once())->method('errorGetLast')->willReturn($error);

        $errorHandler->expects($expectClear)->method('clearOutputBuffer');
        
        $callback = $this->getMockBuilder(\stdClass::class)->setMethods(['__invoke'])->getMock();
        $callback->expects($this->once())->method('__invoke')
            ->with($this->callback(function($error){
                $this->assertInstanceOf(\ErrorException::class, $error);
                $this->assertEquals('no good', $error->getMessage());
                $this->assertEquals('foo.php', $error->getFile());
                $this->assertEquals(42, $error->getLine());
                
                return true;
            }));
        
        $errorHandler->onFatalError($callback, $clearOutput);
        
        $errorHandler->shutdownFunction();
    }
}
